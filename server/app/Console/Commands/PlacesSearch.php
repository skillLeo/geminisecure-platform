<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Gemini\PlaceSearch;
use Illuminate\Console\Command;

/**
 *   php artisan places:search "Portmore, Jamaica"
 *
 * The deploy's check that Places' place search answers (M31 §5.3). The screen's own route answers only a person
 * signed in, and a deploy signs in as nobody, so this asks the same service the route asks, the way the route asks
 * it, and prints one line of JSON: that it answered, how many places, and its one line when there are none. An empty
 * list is an answer: OpenStreetMap being slow or busy is not the release failing (the box then says so, and the map
 * still places by a click or by pasted numbers). Only an error is a failure, and it ends this command non-zero.
 */
class PlacesSearch extends Command
{
    protected $signature = 'places:search {text : a city, an area or an address}';

    protected $description = 'Ask the Places search once, as the screen does, and print what it answered as one line of JSON';

    public function handle(PlaceSearch $places): int
    {
        $answer = $places->search((string) $this->argument('text'));

        $this->line((string) json_encode([
            'answered' => true,
            'results' => count($answer['results']),
            'message' => $answer['message'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
