{{-- Bare card image (no label) — used for the cross intersection where labels would collide --}}
@if (isset($rc) && $rc)
<div class="cc-card-img {{ $rc->reversed ? 'is-reversed' : '' }}">
  <img src="{{ $rc->card->imageUrl() }}" alt="{{ $rc->card->name_th }}" loading="lazy">
</div>
@endif
