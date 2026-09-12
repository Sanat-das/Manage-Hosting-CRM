<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

/**
 * Uploading a file onto a message.
 *
 * Three whitelists, because each one alone is bypassable:
 *
 *   `mimetypes:` trusts the Content-Type the client sent.
 *   `mimes:`     does NOT check the filename — it maps the detected mime type
 *                back to an extension. A file called `script.ps1` containing
 *                plain text passes `mimes:txt` happily. (Verified: it did.)
 *   `extensions:` checks the actual filename extension, which is what decides
 *                what Windows does when the recipient double-clicks it.
 *
 * There is deliberately no "any other file type" escape hatch. A chat that
 * accepts .exe, .ps1, .bat or .hta is a malware delivery channel pointed at
 * your own staff.
 */
class StoreChatAttachmentRequest extends ChatFormRequest
{
    /** 10 MB, in kilobytes, as Laravel's `max:` rule counts it. */
    public const MAX_KILOBYTES = 10240;

    /** The only filename extensions a chat message may carry. */
    public const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
        'pdf', 'txt', 'csv', 'log',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'zip',
    ];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_KILOBYTES,
                'extensions:'.implode(',', self::ALLOWED_EXTENSIONS),
                'mimes:'.implode(',', self::ALLOWED_EXTENSIONS),
                'mimetypes:'.implode(',', [
                    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
                    'application/pdf',
                    'text/plain', 'text/csv', 'application/csv',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.ms-powerpoint',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'application/zip', 'application/x-zip-compressed',
                ]),
            ],
            'inline' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => 'Attachments are limited to 10 MB.',
            'file.mimes' => 'That file type cannot be attached to a chat message.',
            'file.mimetypes' => 'That file type cannot be attached to a chat message.',
            'file.extensions' => 'That file type cannot be attached to a chat message.',
        ];
    }
}
