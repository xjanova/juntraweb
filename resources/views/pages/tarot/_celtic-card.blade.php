@if (isset($rc) && $rc)
<div class="cc-card-wrap">
  <div class="cc-pos-num">{{ $rc->position }}</div>
  <div class="cc-card-img {{ $rc->reversed ? 'is-reversed' : '' }}">
    <img src="{{ $rc->card->imageUrl() }}" alt="{{ $rc->card->name_th }}" loading="lazy">
    <div class="cc-card-glow"></div>
  </div>
  <div class="cc-card-label">
    <div class="cc-pos-text">{{ $rc->position_label }}</div>
    <div class="cc-card-name">{{ $rc->card->name_th }} @if($rc->reversed)<span class="cc-rev">(กลับหัว)</span>@endif</div>
  </div>
</div>
@endif
