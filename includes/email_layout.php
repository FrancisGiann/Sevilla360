<?php
/** Layout shared by built-in transactional emails; no transport or application state. */
function email_layout_document(string $content): string
{
    // Inline template styles provide the compact two-column fallback. Stack only
    // details cells, never colspan headings/notes or caller-authored custom mail.
    return '<!doctype html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<style>@media only screen and (max-width:600px){'
        . '.email-details,.email-details tbody,.email-details tr{display:block!important;width:100%!important;}'
        . '.email-details td{display:block!important;width:auto!important;}'
        . '.email-details .email-label,.email-details .email-value{display:block!important;width:auto!important;text-align:left!important;}'
        . '.email-details .email-label{padding-bottom:4px!important;border-bottom:0!important;}'
        . '.email-details .email-value{padding-top:0!important;border-top:0!important;}'
        . '}</style></head><body style="margin:0;padding:0;width:100%;word-wrap:break-word;overflow-wrap:break-word;-webkit-text-size-adjust:100%;">'
        . $content . '</body></html>';
}
