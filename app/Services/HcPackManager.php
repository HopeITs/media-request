<?php

namespace App\Services;

use App\Models\MediaPackFile;
use App\Models\User;
use App\Support\MediaRequests\HcPack;
use App\Support\MediaRequests\MediaTeam;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The media manager's changes to HC_PACK: add a file, replace one with a newer
 * version, or take one out. Uploaded files are kept on the private local disk
 * (storage/app/private/hc-pack); the drive's own files are never touched.
 */
class HcPackManager
{
    /** Kinds of files the pack holds: logos, templates, documents, print files. */
    public const EXTENSIONS = ['svg', 'png', 'jpg', 'jpeg', 'webp', 'pdf', 'ai', 'eps', 'cdr', 'psd', 'idml', 'indd', 'docx', 'doc', 'xlsx', 'pptx', 'zip', 'ttf', 'otf', 'mp4'];

    public const MAX_KB = 51200;    // 50 MB, as the server allows (DEPLOY.md: upload_max_filesize)

    public function __construct(private HcPack $pack, private MediaTeam $team) {}

    /** Add a new file to a group's folder (and an optional sub-folder under it). */
    public function add(User $user, UploadedFile $file, string $group, string $folder = ''): MediaPackFile
    {
        $this->authorize($user);
        $base = collect(HcPack::GROUPS)->firstWhere(0, $group)[1] ?? throw ValidationException::withMessages(['newGroup' => __('media.hp.m.needGroup')]);
        $folder = trim(collect(preg_split('#[\\\\/]+#', $folder) ?: [])->map(fn ($p) => $this->clean($p))->filter()->implode('/'), '/');
        $path = $base.'/'.($folder !== '' ? $folder.'/' : '').$this->clean($file->getClientOriginalName());

        if ($this->pack->all()->contains('path', $path)) {
            throw ValidationException::withMessages(['newFile' => __('media.hp.m.exists')]);
        }

        return $this->keep($user, $path, $file);
    }

    /**
     * Replace a file with a newer version. Same kind of file: the same place. Another kind
     * (an SVG for a PNG): the new one takes the old one's name with its own extension, and
     * the old one is taken out.
     */
    public function replace(User $user, string $path, UploadedFile $file): MediaPackFile
    {
        $this->authorize($user);
        $old = $this->pack->all()->firstWhere('path', $path) ?? throw ValidationException::withMessages(['replacement' => __('media.hp.m.gone')]);
        $ext = strtolower($file->getClientOriginalExtension());
        $newPath = $ext === $old['ext'] ? $path : preg_replace('/\.[^.\/]+$/', '', $path).'.'.$ext;

        if ($newPath !== $path) {
            $this->remove($user, $path);
        }

        return $this->keep($user, $newPath, $file);
    }

    /** Take a file out of the pack (requests made earlier still name it). */
    public function remove(User $user, string $path): void
    {
        $this->authorize($user);
        $row = MediaPackFile::firstOrNew(['path' => $path]);
        $this->forgetStored($row);
        $row->fill(['stored' => null, 'removed' => true, 'user_id' => $user->id])->save();
    }

    private function keep(User $user, string $path, UploadedFile $file): MediaPackFile
    {
        $row = MediaPackFile::firstOrNew(['path' => $path]);
        $this->forgetStored($row);
        $stored = $file->storeAs('hc-pack/'.Str::uuid(), basename($path), 'local');

        $row->fill(['stored' => $stored, 'size' => (int) $file->getSize(), 'removed' => false, 'user_id' => $user->id]);
        $row->touch();
        $row->save();

        return $row;
    }

    private function forgetStored(MediaPackFile $row): void
    {
        if ($row->stored) {
            Storage::disk('local')->delete($row->stored);
            Storage::disk('local')->deleteDirectory(dirname($row->stored));
        }
    }

    /** A file or folder name safe on every system: no path parts, no characters drives refuse. */
    private function clean(string $name): string
    {
        return trim((string) preg_replace(['#[\\\\/:*?"<>|\x00-\x1F]+#u', '/\s+/u'], ['_', ' '], basename($name)), ' ._');
    }

    private function authorize(User $user): void
    {
        abort_unless($this->team->canActAsMediaManager($user), 403);
    }
}
