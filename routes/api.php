<?php

use App\Http\Resources\TranslationResource;
use App\Models\Translation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('cache.headers:public;max_age=2628000;etag')->group(function () {
    Route::post('/translate', function (Request $request) {
        [, $query] = explode('?', $request->fullUrl() . '?');
        $params = [];
        foreach (explode('&', $request->getContent()) ?? [] as $param) {
            [$key, $value] = explode('=', $param . '=');
            if ($key === 'q') {
                $params[] = urldecode($value);
            }
        }

        if (count($params) === 0) {
            return [
                'data' => [
                    'translations' => []
                ]
            ];
        }

        $cachedTranslations = Translation::query()
            ->where('source', '=', $request->get('source'))
            ->where('target', '=', $request->get('target'))
            ->whereIn('text', $params)
            ->get();

        $missingTranslations = [];

        foreach ($params as $q) {
            $hasCachedTranslation = $cachedTranslations->where('text', '=', $q)->first();

            if (!$hasCachedTranslation) {
                $missingTranslations[] = $q;
            }
        }

        if (count($missingTranslations) > 0) {
            $trResult = Http::post("https://translation.googleapis.com/language/translate/v2?$query", [
                'q' => $missingTranslations
            ])->json('data.translations');

            $saveTranslations = [];

            foreach ($trResult as $key => $translation) {
                if (isset($missingTranslations[$key], $translation['translatedText'])) {
                    $saveTranslations[] = [
                        'source' => $request->get('source'),
                        'target' => $request->get('target'),
                        'text' => $missingTranslations[$key],
                        'translatedText' => $translation['translatedText'],
                        'created_at' => now()->format('Y-m-d H:i:s'),
                        'updated_at' => now()->format('Y-m-d H:i:s')
                    ];
                }
            }

            if (count($saveTranslations) > 0) {
                Translation::query()->insert($saveTranslations);
            }

            $cachedTranslations = $cachedTranslations->merge(
                Translation::query()->whereIn('text', $missingTranslations)->get()
            );
        }

        $translations = [];

        foreach ($params as $q) {
            $hasCachedTranslation = $cachedTranslations->where('text', '=', $q)->first();
            $translations[] = [
                'translatedText' => optional($hasCachedTranslation)->translatedText,
                'origin' => in_array($q, $missingTranslations) ? 'google' : 'cache'
            ];
        }


        return [
            'data' => [
                'translations' => $translations
            ]
        ];
    });

    Route::get('translations/cached', function () {
        return TranslationResource::collection(Translation::query()->latest()->limit(20)->get());
    });
});
