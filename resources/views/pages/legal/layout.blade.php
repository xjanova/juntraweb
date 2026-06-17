{{-- Shared shell for legal/static pages (privacy, terms).
     Theme-agnostic: relies only on utility classes + CSS vars that BOTH
     themes define (.canvas, .panel, .eyebrow, .display, .lede, --gold,
     --ink, --ink-dim, --line). Child pages fill the @yield slots below. --}}
@extends('layouts.app')

@push('head')
<style>
  .legal-body { padding: 40px clamp(20px, 4vw, 52px); }
  .legal-body > :first-child { margin-top: 0; }
  .legal-body h2 {
    font-family: var(--serif, var(--font-thai-display, 'Cormorant Garamond', serif));
    color: var(--gold); font-weight: 500;
    font-size: clamp(20px, 2.6vw, 26px); line-height: 1.3;
    margin: 40px 0 14px; letter-spacing: .01em;
  }
  .legal-body h3 { color: var(--ink); font-size: 16px; font-weight: 600; margin: 22px 0 8px; }
  .legal-body p { color: var(--ink-dim); font-size: 15px; line-height: 1.9; margin: 0 0 14px; }
  .legal-body ul { margin: 0 0 16px; padding-left: 22px; display: flex; flex-direction: column; gap: 9px; }
  .legal-body li { color: var(--ink-dim); font-size: 15px; line-height: 1.75; }
  .legal-body li::marker { color: var(--gold); }
  .legal-body strong { color: var(--ink); font-weight: 600; }
  .legal-body a { color: var(--gold); text-decoration: underline; text-underline-offset: 3px; }
  .legal-note {
    border: 1px solid var(--line, rgba(231, 201, 122, .25));
    border-radius: 14px; padding: 16px 20px;
    background: rgba(231, 201, 122, .06);
    margin: 0 0 20px;
  }
  .legal-note p:last-child { margin-bottom: 0; }
  .legal-meta { margin-top: 16px; font-size: 13px; color: var(--ink-dim); letter-spacing: .04em; }
</style>
@endpush

@section('content')
<section class="canvas" style="padding-top: 160px">
  <div style="max-width: 840px; margin: 0 auto">
    <div style="text-align: center; margin-bottom: 44px">
      <div class="eyebrow" style="display: inline-flex">@yield('eyebrow')</div>
      <h1 class="display" style="font-size: clamp(40px, 5.5vw, 72px); margin-bottom: 18px">@yield('heading')</h1>
      <p class="lede" style="margin: 0 auto">@yield('lede')</p>
      <p class="legal-meta">มีผลบังคับใช้ / ปรับปรุงล่าสุด: @yield('updated')</p>
    </div>

    <div class="panel legal-body">
      @yield('legal')
    </div>

    <p style="text-align: center; margin-top: 28px; font-size: 13.5px; color: var(--ink-dim)">
      @yield('crosslink')
    </p>
  </div>
</section>
@endsection
