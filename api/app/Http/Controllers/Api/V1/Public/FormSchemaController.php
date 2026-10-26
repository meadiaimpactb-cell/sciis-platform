<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Services\FormSchemaService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** FRM-01 — GET /forms/{key}: the active additional questions of a public form (registration / profile / project). */
class FormSchemaController extends Controller
{
    public function __invoke(string $key, FormSchemaService $forms): JsonResponse
    {
        if (! in_array($key, FormSchemaService::PUBLIC_KEYS, true)) {
            throw new NotFoundHttpException;
        }

        return $this->ok($forms->publicSchema($key));
    }
}
