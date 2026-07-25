@props(['text' => ''])
{{--
  Renders a reading as SAFE markdown. Readings and AI replies routinely use
  **bold**, _italics_, and lists; the old `nl2br(e())` showed those markers
  raw. \App\Support\Markdown::safe() renders them properly while stripping any
  embedded HTML (XSS-safe for AI output) and turning single newlines into <br>
  so the original line layout is preserved. Shared with the chat page + mobile
  API so the same message looks identical everywhere.
--}}
<div {{ $attributes->merge(['class' => 'summary']) }}>
{!! \App\Support\Markdown::safe($text) !!}
</div>
