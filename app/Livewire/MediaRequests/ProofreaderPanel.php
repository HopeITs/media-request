<?php

namespace App\Livewire\MediaRequests;

use App\Models\MediaProofread;
use App\Support\MediaRequests\Proofreader;
use App\Support\MediaRequests\WordText;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The proofreader window, for everyone: paste a text, upload a Word file, or
 * open it from a form field; check it, see what changed, copy it, download it
 * as Word, or put it back in the field.
 *
 * Every check is kept for the person who asked ("My checks"), newest first,
 * up to MediaProofread::KEEP; nobody else sees them and they can delete them.
 * The text goes to Gemini only to be checked.
 *
 * On the home screen it sits inside its card (inline); elsewhere it is a pop-up
 * opened with the "media-proofread-open" event: { text, target }. "target" is
 * the field to send the corrected text back to ("media-proofread-apply").
 */
class ProofreaderPanel extends Component
{
    use WithFileUploads;

    public bool $show = false;

    /** On the home screen card: always open, no pop-up, and it does not answer the open event. */
    #[Locked]
    public bool $inline = false;

    /** "check" (paste or upload) or "history" (my earlier checks). */
    public string $tab = 'check';

    public string $text = '';

    /** An uploaded .docx or .txt. */
    public $file = null;

    /** Where the text came from: "text" or the file name. */
    #[Locked]
    public string $source = 'text';

    /** Where the corrected text goes back to, if it came from a form field. */
    #[Locked]
    public ?string $target = null;

    public ?array $result = null;

    /** The saved check on screen, for downloading it. */
    #[Locked]
    public ?int $savedId = null;

    #[On('media-proofread-open')]
    public function open(?string $text = null, ?string $target = null): void
    {
        if ($this->inline) {
            return;   // the pop-up answers; the card stays as it is
        }

        $this->reset(['tab', 'file', 'source', 'result', 'savedId']);
        $this->text = mb_substr((string) $text, 0, Proofreader::MAX_CHARS);
        $this->target = in_array($target, ['description', 'note', 'body'], true) ? $target : null;
        $this->show = true;
    }

    public function close(): void
    {
        $this->reset(['show', 'tab', 'text', 'file', 'source', 'target', 'result', 'savedId']);
        $this->show = $this->inline;
    }

    /** A Word or text file was chosen: read its text into the box. */
    public function updatedFile(WordText $word): void
    {
        $this->validate(['file' => ['file', 'max:10240', 'mimes:docx,txt']], [], ['file' => __('media.proof.file')]);

        $name = $this->file->getClientOriginalName();
        $text = strtolower($this->file->getClientOriginalExtension()) === 'docx'
            ? $word->read($this->file->getRealPath())
            : (string) file_get_contents($this->file->getRealPath());

        if ($text === null || trim($text) === '') {
            $this->file = null;
            $this->addError('file', __('media.proof.fileEmpty'));

            return;
        }

        $this->text = mb_substr($text, 0, Proofreader::MAX_CHARS);
        $this->source = mb_substr($name, 0, 190);
        $this->file = null;
        if (mb_strlen($text) > Proofreader::MAX_CHARS) {
            $this->addError('file', __('media.proof.fileLong', ['n' => number_format(Proofreader::MAX_CHARS)]));
        }
    }

    public function check(Proofreader $proofreader): void
    {
        $this->validate(['text' => ['required', 'string', 'max:'.Proofreader::MAX_CHARS]], [], ['text' => __('media.proof.text')]);

        $this->result = $proofreader->check($this->text, (int) auth()->id());

        $saved = MediaProofread::create([
            'user_id' => auth()->id(),
            'source' => $this->source,
            'original' => $this->result['original'],
            'corrected' => $this->result['corrected'],
            'result' => collect($this->result)->except(['original', 'corrected'])->all(),
        ]);
        $this->savedId = $saved->id;

        // Keep the newest checks only.
        MediaProofread::where('user_id', auth()->id())->latest('id')->skip(MediaProofread::KEEP)->take(1000)->pluck('id')
            ->whenNotEmpty(fn ($old) => MediaProofread::whereIn('id', $old)->delete());
    }

    /** Use the corrected text: in the field it came from, or as the new text to work on. */
    public function accept(): void
    {
        if (! $this->result) {
            return;
        }

        if ($this->target) {
            $this->dispatch('media-proofread-apply', target: $this->target, text: $this->result['corrected']);
            $this->close();

            return;
        }

        $this->text = $this->result['corrected'];
        $this->reset(['result', 'savedId']);
    }

    public function again(): void
    {
        $this->reset(['result', 'savedId', 'file']);
        $this->source = 'text';
        $this->tab = 'check';
    }

    /** Open one of my earlier checks. */
    public function openSaved(int $id): void
    {
        $saved = $this->mine()->findOrFail($id);
        $this->result = ['original' => $saved->original, 'corrected' => $saved->corrected] + (array) $saved->result + [
            'changes' => [], 'suggestions' => [], 'summary' => '', 'language' => 'ar', 'ai' => false, 'aiError' => null, 'localFixes' => [],
        ];
        $this->text = $saved->original;
        $this->source = $saved->source;
        $this->savedId = $saved->id;
        $this->tab = 'check';
    }

    public function deleteSaved(int $id): void
    {
        $this->mine()->whereKey($id)->delete();
        if ($this->savedId === $id) {
            $this->again();
            $this->tab = 'history';
        }
    }

    /** The checked text as a Word file. */
    public function download(WordText $word)
    {
        $saved = $this->savedId ? $this->mine()->find($this->savedId) : null;
        if (! $saved) {
            return null;
        }

        $base = $saved->source !== 'text' ? pathinfo($saved->source, PATHINFO_FILENAME) : 'text';
        $name = Str::slug($base, '_', 'en') ?: 'text';
        $rtl = ($saved->result['language'] ?? 'ar') === 'ar';

        return response()->streamDownload(fn () => print($word->write($saved->corrected, $rtl)), $name.'_checked.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    public function render(Proofreader $proofreader)
    {
        return view('livewire.media-requests.proofreader-panel', [
            'aiAvailable' => $proofreader->aiAvailable(),
            'diff' => $this->result ? $proofreader->diff($this->result['original'], $this->result['corrected']) : [],
            'remaining' => $proofreader->remaining((int) auth()->id()),
            'history' => ($this->show || $this->inline) && $this->tab === 'history' ? $this->mine()->latest('id')->limit(MediaProofread::KEEP)->get() : collect(),
            'count' => $this->show || $this->inline ? $this->mine()->count() : 0,
        ]);
    }

    private function mine()
    {
        return MediaProofread::query()->where('user_id', auth()->id());
    }
}
