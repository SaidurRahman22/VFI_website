<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 3F — admin image upload + media-slot registry. content_editor/owner
 * only. Uploads are magic-byte-validated, re-encoded, content-hashed.
 */
class AdminMediaController extends Controller
{
    public function __construct(private readonly ImageService $images) {}

    public function upload(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canEditContent(), 403);

        $request->validate([
            // server-side mime check, SVG excluded (script-carrying), ~6 MB raw cap.
            // The real magic-byte defense is ImageService (GD decode) below.
            'file' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:6144'],
            'max_width' => ['nullable', 'integer', 'min:200', 'max:4000'],
            'quality' => ['nullable', 'integer', 'min:40', 'max:95'],
        ]);

        try {
            $id = $this->images->store(
                $request->file('file'),
                (int) $request->input('max_width', 1400),
                (int) $request->input('quality', 82),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => 'That file is not a valid image.'], 422);
        }

        return response()->json(['imgId' => $id], 201)->header('Cache-Control', 'no-store');
    }

    public function setSlot(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()?->canEditContent(), 403);

        $data = $request->validate(['imgId' => ['nullable', 'string', 'max:255']]);
        $media = $this->images->setMedia($key, $data['imgId'] ?? null);

        return response()->json(['media' => $media])->header('Cache-Control', 'no-store');
    }
}
