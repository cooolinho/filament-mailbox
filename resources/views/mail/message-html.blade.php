<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin: 0; padding: 0;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td class="message">
            {{-- $html passed HtmlBodySanitizer::outgoing() before it reached this view. --}}
            {!! $html !!}
        </td>
    </tr>
</table>
</body>
</html>
