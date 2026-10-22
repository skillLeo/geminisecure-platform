<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The golden payslips — Q-002, RULED (D-082)
|--------------------------------------------------------------------------
|
| The client ruled: PAYE is 25% on the amount ABOVE the threshold, a band on the
| excess and never a flat rate on the whole. This platform's calculation was
| right and the sample payslips were wrong. These are the ruling's own two
| worked examples, to the cent, and a one-cent divergence fails the build.
|
| TWO CARDS, BECAUSE A CALENDAR YEAR SPANS TWO THRESHOLDS. The threshold changes
| every 1 April and income tax is assessed on a calendar year, so January to
| March 2026 is the 2025-04 card (monthly threshold 149,948.00) and April to
| December the 2026-04 card (158,530.00). The same two people on both cards
| prove the version selector: Patricia's PAYE moves from 5,230.00 to 7,375.50
| and Simone's stays nil.
|
| SIMONE CLARKE'S SLIP IS THE IMPORTANT ONE. Nil PAYE beside non-nil Education
| Tax proves three things at once: the threshold is applied AS a threshold, PAYE
| is a band and not a cliff, and Education Tax ignores the threshold entirely.
|
| NOT COUNTERSIGNED. The figures come from TAJ publications and the client's
| ruling on method, and reconcile to the cent. The client's accountant has not
| signed them, which is why the first live pay run carries an acknowledgement
| (Part F of the ruling) — and why `source` below says so.
|
*/

return [
    'confirmed' => true,

    'source' => "TAJ published tables and the client's ruling on method (Q-002, D-082). Not countersigned "
        ."by the client's accountant — the first live pay run carries that acknowledgement.",

    'periods_per_year' => 12,

    /*
     * The two cards, by a pay date inside each. The selector is asked for the
     * card on that date, so this fixture proves the seeded cards AND the
     * selector rather than a card built in memory.
     */
    'versions' => [
        'apr_dec_2026' => ['pay_date' => '2026-08-31', 'label' => 'TAJ 2026/27', 'monthly_threshold_minor' => 158_530_00],
        'jan_mar_2026' => ['pay_date' => '2026-02-28', 'label' => 'TAJ 2025/26', 'monthly_threshold_minor' => 149_948_00],
    ],

    'employees' => [
        'Patricia Morgan' => [
            'gross_minor' => 185_000_00,

            'expected' => [
                /*
                 * Gross 185,000.00 · NIS 5,550.00 · statutory income 179,450.00
                 * · Education Tax 2.25% of 179,450.00 · PAYE 25% of 20,920.00
                 * (179,450.00 − 158,530.00) · NHT 5,550.00? no — 2% of gross.
                 * Total deductions 18,517.63, net 166,482.37.
                 */
                'apr_dec_2026' => [
                    'nis_minor' => 5_550_00,
                    'education_tax_minor' => 4_037_63,
                    'paye_minor' => 5_230_00,
                    'nht_minor' => 3_700_00,
                    'net_minor' => 166_482_37,

                    // The employer's own cost, never deducted from her.
                    'employer_nis_minor' => 5_550_00,
                    'employer_nht_minor' => 5_550_00,
                    'employer_education_tax_minor' => 6_280_75,
                    'employer_heart_minor' => 5_550_00,
                    'employer_total_minor' => 22_930_75,
                ],

                // 25% of 29,502.00 (179,450.00 − 149,948.00).
                'jan_mar_2026' => [
                    'paye_minor' => 7_375_50,
                ],
            ],

            // Board 15's own PAYE column, recorded and never treated as correct.
            'board_states' => ['paye_minor' => 42_928_00],
        ],

        'Simone Clarke' => [
            'gross_minor' => 72_000_00,

            'expected' => [
                'apr_dec_2026' => [
                    'nis_minor' => 2_160_00,
                    'education_tax_minor' => 1_571_40,
                    'paye_minor' => 0,
                    'nht_minor' => 1_440_00,
                    'net_minor' => 66_828_60,

                    'employer_nis_minor' => 2_160_00,
                    'employer_nht_minor' => 2_160_00,
                    'employer_education_tax_minor' => 2_444_40,
                    'employer_heart_minor' => 2_160_00,
                    'employer_total_minor' => 8_924_40,
                ],

                'jan_mar_2026' => [
                    'paye_minor' => 0,
                ],
            ],

            'board_states' => ['paye_minor' => 0],
        ],
    ],

    /*
     * The August run's PAYE, both ways, because two multiples can be read off
     * it and they are easy to swap. Every client-facing sentence quotes the money.
     */
    'run_paye_minor' => [
        'Patricia Morgan' => ['board' => 42_928_00, 'lawful' => 5_230_00],
        'Neil Anderson' => ['board' => 22_044_00, 'lawful' => 0],
        'Wayne Thomas' => ['board' => 20_420_00, 'lawful' => 0],
        'Simone Clarke' => ['board' => 0, 'lawful' => 0],
    ],

    /*
     * Where board 15's cliff sat. Thomas's base of 81,679.40 is charged and
     * Clarke's 66,828.60 is not, so four observations could only narrow it to
     * this window — and four divisors of the old annual figure landed inside it,
     * so the board alone could not say which (D-073). The ruling identified it:
     * TAJ's published 2026 FORTNIGHTLY threshold, 73,234.90, applied to monthly
     * pay. It is not any whole-number division of an annual figure, which is
     * exactly why "narrowed, not identified" was the honest thing to report.
     */
    'cliff_window_minor' => ['above' => 66_828_60, 'up_to' => 81_679_40],
    'board_cliff_minor' => 73_234_90,
];
