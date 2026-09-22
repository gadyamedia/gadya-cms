<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Content\EditableFields;
use Gadya\Cms\Content\SiteMarkdown;
use Gadya\Cms\Http\Requests\InlineEditRequest;
use Gadya\Cms\Services\UpdateDraftField;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use RuntimeException;

class InlineEditController extends Controller
{
    public function update(InlineEditRequest $request, UpdateDraftField $action): JsonResponse
    {
        try {
            $action->handle(
                $request->string('path')->toString(),
                $request->string('value')->toString(),
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $path = $request->string('path')->toString();

        /* A Markdown field is re-drawn from the server, so the page shows what visitors will. */
        return response()->json(array_filter([
            'saved' => true,
            'html' => app(EditableFields::class)->typeFor($path) === 'markdown'
                ? (string) app(SiteMarkdown::class)->render($request->string('value')->toString())
                : null,
        ], fn ($value): bool => $value !== null));
    }
}
