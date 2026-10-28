@props(['active' => false , 'activeTab' => 'all'])

<li class="me-2">
     <button type="button"
        {{$attributes->merge([
     'class' =>  $active ?  'inline-block px-4 py-3 rounded-control text-white bg-primary' : 'inline-block px-4 py-3 rounded-control hover:text-neutral-900 hover:bg-neutral-100'
 ])}}
        wire:click="setActiveTab('{{$activeTab}}')">
        {{$slot}}
     </button>
</li>
