<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditingLock;
use Gadya\Cms\Http\Requests\StructureRequest;
use Gadya\Cms\Services\UpdateDraftStructure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * Changes to the shape of the draft from the live editor: items in a section, sections on a page, a
 * gallery's photo slots and the site's link lists. Words and photos go through the inline editor.
 */
class StructureController extends Controller
{
    public function update(StructureRequest $request, UpdateDraftStructure $structure, EditingLock $lock, SiteContentRepository $repository): JsonResponse
    {
        if ($lock->holder() !== null && ! $lock->isHeldBy($request->user())) {
            return response()->json(['message' => "{$lock->holder()} is editing right now. Wait until they finish."], 423);
        }

        $sectionPath = $request->string('section_path')->toString();
        $page = $request->string('page')->toString();
        $list = $request->string('list')->toString();

        try {
            $repository->withDraftLock(fn () => match ($request->string('operation')->toString()) {
                'add-item' => $structure->addItem($sectionPath, $request->array('item')),
                'remove-item' => $structure->removeItem($sectionPath, $request->integer('index')),
                'reorder-items' => $structure->reorderItems($sectionPath, array_map('intval', $request->array('order'))),
                'choose-form' => $structure->chooseForm($sectionPath, $request->string('form')->toString()),
                'add-photo' => $structure->addPhotoSlot($sectionPath),
                'add-section' => $structure->insertSection($page, $request->string('type')->toString(), $request->integer('position')),
                'move-section' => $structure->moveSection($page, $request->integer('index'), $request->integer('step')),
                'remove-section' => $structure->removeSection($page, $request->integer('index')),
                'add-link' => $structure->addLink($list),
                'remove-link' => $structure->removeLink($list, $request->integer('index')),
            });
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'Another change is still saving. Try again in a moment.'], 409);
        }

        $lock->acquire($request->user());

        return response()->json(['saved' => true]);
    }
}
