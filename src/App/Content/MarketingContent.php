<?php

namespace Keel\App\Content;

/**
 * The marketing site's content registry.
 *
 * FAQ answers live here rather than in the view because three things render
 * from them - the visible page, the FAQPage JSON-LD, and llms.txt - and Google
 * requires the structured answer to match the visible one exactly. One source
 * makes drifting apart impossible.
 *
 * Answers are written to the pattern that gets quoted: the direct answer first,
 * in two or three self-contained sentences, before any elaboration. Each answer
 * has to make sense lifted out of the page with no surrounding context.
 */
class MarketingContent
{
    /**
     * @return array<int, array{slug: string, question: string, answer: string, elaboration: array<int, string>}>
     */
    public static function faqs(): array
    {
        return [
            [
                'slug' => 'are-football-squares-legal-for-a-dealership',
                'question' => 'Are football squares legal for a car dealership to run?',
                'answer' => 'A football squares board is legal for a car dealership to run in most US states when '
                    . 'entry is free, because a promotion is only an illegal lottery when it combines all three of '
                    . 'prize, chance and consideration. Removing the entry fee removes consideration, which turns the '
                    . 'board into a sweepstakes rather than a lottery. The traditional office pool is illegal in most '
                    . 'states precisely because everyone pays in.',
                'elaboration' => [
                    'The distinction is not about football, and it is not about the grid. It is about whether '
                        . 'entrants give up something of value to take part. A dealership board where anyone can '
                        . 'claim a square at no cost, with no purchase and no obligation, is structurally the same '
                        . 'as a mail-in giveaway.',
                    'Two things commonly break this. The first is charging for squares, even a token amount or a '
                        . 'donation. The second is requiring a purchase, a test drive, or a service appointment to '
                        . 'enter, which can be treated as consideration in some states. Keep entry genuinely free '
                        . 'and open, and the analysis stays simple.',
                    'State law varies and this is not legal advice. Have your own counsel review your rules before '
                        . 'your first board goes live, particularly if you operate in more than one state.',
                ],
            ],
            [
                'slug' => 'sweepstakes-vs-lottery',
                'question' => 'What makes a promotion a sweepstakes instead of a lottery?',
                'answer' => 'A promotion is a lottery when it has all three of prize, chance and consideration, and a '
                    . 'sweepstakes when the consideration is removed so only prize and chance remain. Consideration '
                    . 'normally means paying money or making a purchase to enter. Take the payment requirement away '
                    . 'and the same prize, awarded by the same random draw, is no longer a lottery.',
                'elaboration' => [
                    'The third variant is a contest, where the winner is determined by skill rather than chance. '
                        . 'Contests can sometimes charge an entry fee for that reason, but judging has to be genuine '
                        . 'and the criteria published, which is far more operational work than a dealership promotion '
                        . 'usually justifies.',
                    'The phrase "no purchase necessary" exists to document that consideration has been removed. It is '
                        . 'not a magic incantation - it has to be true. If the only realistic way to enter is to buy '
                        . 'something, printing the words changes nothing.',
                ],
            ],
            [
                'slug' => 'collect-customer-contact-info-legally',
                'question' => 'How do dealerships collect customer contact information legally?',
                'answer' => 'A dealership collects contact information legally by asking for it directly, disclosing '
                    . 'what it will be used for, and recording the customer\'s consent at the moment they give it. '
                    . 'For text messaging specifically, US law requires prior express written consent before you send '
                    . 'marketing messages, and that consent cannot be a condition of buying anything. Keep a record '
                    . 'of what each person agreed to and when.',
                'elaboration' => [
                    'In practice that means a consent checkbox that is not pre-ticked into a purchase, language next '
                        . 'to it describing what will be sent and roughly how often, and a stored timestamp you can '
                        . 'produce if you are ever asked to prove it. Buying a list, scraping numbers, or texting '
                        . 'everyone in your DMS who never opted in is where dealerships get into trouble.',
                    'Every message you send also needs to identify your business and tell the recipient how to stop. '
                        . 'Honouring an opt-out promptly and permanently is not optional, and it should apply across '
                        . 'every campaign you run, not just the one they replied to.',
                ],
            ],
            [
                'slug' => 'what-does-a-service-drive-promotion-cost',
                'question' => 'What does a service drive promotion cost to run?',
                'answer' => 'The real cost of a service drive promotion is the internal cost of the prizes you give '
                    . 'away, not their retail value. A dealership giving away four service offers on one board '
                    . 'typically spends parts, labour and bay time rather than cash, and only spends it when somebody '
                    . 'actually wins and redeems. Software and messaging costs are usually a small fixed monthly '
                    . 'figure next to that.',
                'elaboration' => [
                    'Work it out per contact rather than per board. Divide what the prizes cost you internally by the '
                        . 'number of people who entered and gave you a working mobile number with consent attached. '
                        . 'Compare that figure to what you currently pay per lead through your other channels - that '
                        . 'comparison is the only one that matters, and it is yours to run with your own numbers.',
                    'There is a second return that does not show up in the prize cost. A winner has to come back into '
                        . 'your service drive to redeem, which puts a lapsed customer in front of an advisor with a '
                        . 'vehicle on a lift.',
                ],
            ],
            [
                'slug' => 'do-i-need-a-license-for-a-squares-board',
                'question' => 'Do I need a license to run a squares board at my dealership?',
                'answer' => 'A dealership running a free-entry squares board does not normally need a gaming licence, '
                    . 'because a licence applies to gambling and a free promotion is not gambling. What can apply is '
                    . 'sweepstakes registration, which a few states require above a prize-value threshold. Those '
                    . 'thresholds are generally well above the value of a typical service offer.',
                'elaboration' => [
                    'Florida and New York have historically required registration and a bond for sweepstakes with a '
                        . 'total prize value above $5,000, and Rhode Island has required registration for retail '
                        . 'sweepstakes above $500. A board giving away an oil change, a rotation and a detail sits '
                        . 'well under the first two and should be checked against the third.',
                    'Thresholds, filing windows and bonding requirements change, and they are state-specific. Confirm '
                        . 'the current position for every state you operate in with your own counsel before you '
                        . 'launch, rather than relying on a figure quoted on a vendor website.',
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{slug: string, title: string, heading: string, description: string, summary: string, updated: string}>
     */
    public static function guides(): array
    {
        return [
            [
                'slug' => 'legal-football-squares-promotions-for-dealerships',
                'title' => 'How dealerships run legal football squares promotions',
                'heading' => 'How dealerships run legal football squares promotions',
                'description' => 'What separates a legal dealership squares board from an illegal office pool, and '
                    . 'the operational rules that keep a free-entry promotion on the right side of the line.',
                'summary' => 'The legal test, the four things that break it, and how to run a board that passes.',
                'updated' => '2026-09-01',
            ],
            [
                'slug' => 'service-drive-customer-retention-ideas',
                'title' => 'Service drive customer retention ideas that actually work',
                'heading' => 'Service drive customer retention ideas that actually work',
                'description' => 'Retention tactics for a dealership service drive that survive contact with a busy '
                    . 'Saturday, and why most retention programmes fail on execution rather than on strategy.',
                'summary' => 'Why retention programmes fail on execution, and six that survive a busy Saturday.',
                'updated' => '2026-09-01',
            ],
            [
                'slug' => '10dlc-registration-for-dealership-text-messaging',
                'title' => '10DLC registration for dealership text messaging',
                'heading' => '10DLC registration for dealership text messaging',
                'description' => 'What 10DLC registration is, why dealership texts get filtered without it, and the '
                    . 'order to do brand registration, campaign registration and consent capture in.',
                'summary' => 'What it is, why unregistered dealership texts disappear, and the order to do it in.',
                'updated' => '2026-09-01',
            ],
            [
                'slug' => 'free-entry-promotions-vs-sweepstakes-vs-lottery',
                'title' => 'Free-entry promotions vs sweepstakes vs lottery',
                'heading' => 'Free-entry promotions vs sweepstakes vs lottery',
                'description' => 'The three-element test that separates a lottery from a sweepstakes from a contest, '
                    . 'and what counts as consideration when a dealership runs a giveaway.',
                'summary' => 'Prize, chance and consideration - the test, and where dealerships trip over it.',
                'updated' => '2026-09-01',
            ],
        ];
    }

    public static function findGuide(string $slug): ?array
    {
        foreach (self::guides() as $guide) {
            if ($guide['slug'] === $slug) {
                return $guide;
            }
        }

        return null;
    }

    /**
     * Every marketing URL that belongs in the sitemap, as an explicit list.
     *
     * Deliberately not derived from the router: an allowlist cannot accidentally
     * start publishing claim pages or admin routes when a route is added later.
     *
     * @return array<int, array{path: string, changefreq: string, priority: string}>
     */
    public static function sitemapPaths(): array
    {
        $paths = [
            ['path' => '/', 'changefreq' => 'weekly', 'priority' => '1.0'],
            ['path' => '/demo', 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['path' => '/faq', 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['path' => '/guides', 'changefreq' => 'monthly', 'priority' => '0.6'],
        ];

        foreach (self::guides() as $guide) {
            $paths[] = ['path' => '/guides/' . $guide['slug'], 'changefreq' => 'yearly', 'priority' => '0.7'];
        }

        return $paths;
    }
}
