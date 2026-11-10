@props(['url'])
{{-- SUTURA logo (PNG: Gmail and Outlook don't show SVG), linked to the website. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('images/email-logo.png') }}" class="logo" width="166" height="44" alt="{{ trim($slot) ?: 'SUTURA' }}">
</a>
</td>
</tr>
