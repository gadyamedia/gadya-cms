<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Forms\StoreFormSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

/**
 * Where the site's forms post to. Open to visitors by necessity, so it is
 * throttled, rejects anything that filled in the honeypot, and keeps only
 * the fields the form was configured with.
 */
class FormSubmissionController extends Controller
{
    public function store(Request $request, string $form, StoreFormSubmission $store): JsonResponse|RedirectResponse
    {
        $definition = FormDefinition::find($form);

        abort_if($definition === null, 404);

        $honeypot = (string) config('gadya-cms.forms.honeypot', 'website');

        /*
         * A bot fills in every field it can see, including the one a
         * person never sees. Answer as though it worked, so it learns
         * nothing.
         */
        if ($honeypot !== '' && filled($request->input($honeypot))) {
            return $this->success($request, $definition);
        }

        $validator = Validator::make($request->all(), $definition->rules);

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            return back()
                ->withErrors($validator, 'gadya-cms.'.$definition->name)
                ->withInput($request->except($honeypot));
        }

        $store->handle($definition, $validator->validated(), $request);

        return $this->success($request, $definition);
    }

    private function success(Request $request, FormDefinition $form): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $form->success]);
        }

        $to = (string) $request->input('_redirect', '');

        $response = $to !== '' && str_starts_with($to, '/') && ! str_starts_with($to, '//')
            ? redirect()->to($to)
            : back();

        return $response->with('gadya-cms.form.'.$form->name, $form->success);
    }
}
