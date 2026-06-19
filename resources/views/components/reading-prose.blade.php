@props(['text' => ''])
{{--
  Renders a reading as SAFE markdown. Readings and AI replies routinely use
  **bold**, _italics_, and lists; the old `nl2br(e())` showed those markers
  raw. CommonMark renders them properly while `html_input => strip` removes any
  embedded HTML (XSS-safe for AI output) and single newlines become <br> so the
  original line layout is preserved.
--}}
<div {{ $attributes->merge(['class' => 'summary']) }}>
{!! \Illuminate\Support\Str::markdown((string) $text, [
    'html_input' => 'strip',
    'allow_unsafe_links' => false,
    'renderer' => ['soft_break' => "<br />\n"],
]) !!}
</div>
