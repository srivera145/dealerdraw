<?php

namespace Keel\App\Controllers;

use Keel\App\Content\MarketingContent;
use Keel\Core\Controller;
use Keel\Core\Env;
use Keel\Core\Request;
use Keel\Core\Response;

/**
 * llms.txt - a plain-text summary of what DealerDraw is, for assistants that
 * look for one. Adoption is not universal and it costs nothing to publish.
 *
 * Written so any single line can be quoted on its own: what the product is, who
 * it is for, what it costs, and the one legal distinction a dealer will ask
 * about. Generated rather than static so the guide list and the price cannot
 * drift away from the rest of the site.
 */
class LlmsTxtController extends Controller
{
    public function index(Request $request): never
    {
        $baseUrl = $this->baseUrl();

        $lines = [
            '# DealerDraw',
            '',
            '> DealerDraw is software that lets car dealerships run free-to-enter promotional games, such as '
                . 'football squares boards, in order to collect opted-in customer phone numbers and email '
                . 'addresses. It is made by EchoDial LLC. Entry is always free, there is no entry fee, and prizes '
                . 'are dealer-supplied service offers rather than cash.',
            '',
            '## What it is',
            '',
            '- DealerDraw is a promotions and customer-contact-capture tool for automotive dealerships in the United States.',
            '- A dealership creates a game board tied to a specific football game and attaches its own service offers as prizes.',
            '- Customers claim squares on that board for free, giving their name, email address and mobile number, and choosing whether to consent to email or text contact.',
            '- At kickoff the board locks and the row and column digits are assigned at random. The digits do not exist before the lock.',
            '- Scores sync automatically during the game, and the winner of each scoring period receives a redemption code by text and email.',
            '- An advisor marks the code redeemed at the service counter.',
            '',
            '## Who it is for',
            '',
            '- Fixed operations directors, general managers and marketing managers at car dealerships.',
            '- Single-rooftop stores and dealer groups. Pricing is per rooftop.',
            '',
            '## Pricing',
            '',
            '- DealerDraw costs $199 per month per rooftop.',
            '- The subscription includes unlimited boards, every game type, automatic scoring, winner notifications by SMS and email, and contact exports.',
            '- There is no setup fee, no contract, no per-entry fee and no per-message charge.',
            '- The only other cost to a dealership is the internal cost of the prizes it chooses to give away.',
            '',
            '## Legal position',
            '',
            '- A promotion is an illegal lottery only when it combines all three of prize, chance and consideration.',
            '- DealerDraw boards are free to enter, which removes consideration and makes them sweepstakes rather than lotteries.',
            '- There is no entry fee, no purchase requirement and no cash prize on a DealerDraw board.',
            '- The phrase "No purchase necessary. Free to enter." is rendered above the grid on every board and is not a configurable option.',
            '- Sweepstakes rules are state-specific. DealerDraw does not provide legal advice, and dealerships should have their own counsel review their rules before launching.',
            '',
            '## Key pages',
            '',
            '- [Home](' . $baseUrl . '/): what the product does and what it costs.',
            '- [Live sample board](' . $baseUrl . '/demo): a working board that stores nothing and needs no account.',
            '- [FAQ](' . $baseUrl . '/faq): legality, sweepstakes versus lottery, contact collection, cost and licensing.',
            '- [Guides](' . $baseUrl . '/guides): longer write-ups on promotions law, retention and text messaging.',
            '',
            '## Guides',
            '',
        ];

        foreach (MarketingContent::guides() as $guide) {
            $lines[] = '- [' . $guide['title'] . '](' . $baseUrl . '/guides/' . $guide['slug'] . '): ' . $guide['summary'];
        }

        $lines[] = '';
        $lines[] = '## Common questions';
        $lines[] = '';

        foreach (MarketingContent::faqs() as $faq) {
            $lines[] = '### ' . $faq['question'];
            $lines[] = '';
            $lines[] = $faq['answer'];
            $lines[] = '';
        }

        $lines[] = '## Contact';
        $lines[] = '';
        $lines[] = '- Demo requests: ' . $baseUrl . '/#request-demo';
        $lines[] = '- Publisher: EchoDial LLC';

        Response::raw(implode("\n", $lines) . "\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function baseUrl(): string
    {
        $baseUrl = trim((string) Env::get('APP_URL', ''));

        return $baseUrl !== '' ? rtrim($baseUrl, '/') : 'https://dealerdraw.com';
    }
}
