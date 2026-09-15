<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Http\Requests\StructureRequest;
use Gadya\Cms\Services\UpdateDraftStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

class StructureController extends Controller
{
    public function update(StructureRequest $request, UpdateDraftStructure $structure): JsonResponse
    {
        $sectionPath = $request->string('section_path')->toString();

        try {
            match ($request->string('operation')->toString()) {
                'add-item' => $structure->addItem($sectionPath, $request->array('item')),
                'remove-item' => $structure->removeItem($sectionPath, $request->integer('index')),
                'reorder-items' => $structure->reorderItems($sectionPath, array_map('intval', $request->array('order'))),
            };
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['saved' => true]);
    }
}
