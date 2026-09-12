<?php

namespace App\Http\Controllers\Api\News;

use App\Http\Controllers\Controller;
use App\Services\NewsService;
use App\Traits\ResponseStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewsController extends Controller
{
    use ResponseStructure;

    public function __construct(protected NewsService $news) {}

    public function index(Request $request): JsonResponse
    {
        $symbol = $request->query('symbol');

        return $this->paginated(
            $this->news->recentNews(
                is_string($symbol) && $symbol !== '' ? $symbol : null,
            ),
            'News articles',
        );
    }
}
