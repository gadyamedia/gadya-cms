<?php

namespace Gadya\Cms\Http\Controllers;

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

        return response()->json(['saved' => true]);
    }
}
