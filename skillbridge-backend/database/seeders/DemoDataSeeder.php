<?php

namespace Database\Seeders;

use App\Models\Bid;
use App\Models\Category;
use App\Models\Contract;
use App\Models\ContractMilestone;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Dispute;
use App\Models\EscrowAccount;
use App\Models\EscrowEntry;
use App\Models\Favorite;
use App\Models\JobPost;
use App\Models\Notification;
use App\Models\PaymentTransaction;
use App\Models\PortfolioItem;
use App\Models\ProviderAvailability;
use App\Models\ProviderServiceArea;
use App\Models\ProviderVerification;
use App\Models\Review;
use App\Models\SavedJob;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\Skill;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedTaxonomy();
            $users = $this->seedUsers();
            $skills = $this->seedSkills();

            $this->seedProviderProfiles($users, $skills);
            $jobs = $this->seedJobs($users, $skills);
            $this->seedBids($users, $jobs);
            $contracts = $this->seedContracts($users, $jobs);
            $this->seedPayments($users, $contracts);
            $this->seedReviews($users, $contracts);
            $this->seedServices($users);
            $this->seedPortfolios($users);
            $this->seedSavedJobsAndFavorites($users, $jobs);
            $this->seedMessaging($users);
            $this->seedNotifications($users);
            $this->seedDisputes($users, $contracts);
            $this->seedAdminAuditLogs($users);
        });
    }

    private function seedTaxonomy(): void
    {
        $categories = [
            'Home & Repair' => [
                'Plumbing',
                'Electrical',
                'AC Repair',
                'Carpentry',
                'Painting',
                'Masonry',
                'Cleaning',
                'Handyman',
            ],
            'Beauty' => [
                'Mehendi Design',
                'Makeup',
                'Hair Styling',
                'Beautician',
                'Nail Art',
            ],
            'Events & Creative' => [
                'Photography',
                'Videography',
                'Video Editing',
                'Decoration',
                'Event Planning',
                'DJ',
            ],
            'Design & Media' => [
                'Graphic Design',
                'UI/UX Design',
                'Illustration',
                'Motion Design',
                'Content Creation',
            ],
            'Technology' => [
                'Web Development',
                'React Development',
                'Laravel Development',
                'PHP Development',
                'JavaScript Development',
                'Mobile Development',
                'QA & Testing',
                'IT Support',
            ],
            'Business' => [
                'Accounting',
                'Translation',
                'Content Writing',
                'Digital Marketing',
                'SEO',
                'Virtual Assistant',
            ],
            'Education' => [
                'Academic Tutor',
                'English Tutor',
                'Language Teacher',
                'Music Teacher',
                'Fitness Coach',
            ],
            'Other Services' => [
                'Tailoring',
                'Chef / Cook',
                'Moving Help',
            ],
        ];

        foreach ($categories as $categoryName => $subcategories) {
            $category = Category::updateOrCreate(
                ['slug' => str($categoryName)->slug()],
                [
                    'name' => $categoryName,
                    'description' => "Professional {$categoryName} services.",
                    'is_active' => true,
                ]
            );

            foreach ($subcategories as $subcategoryName) {
                Subcategory::updateOrCreate(
                    [
                        'category_id' => $category->id,
                        'slug' => str($subcategoryName)->slug(),
                    ],
                    [
                        'name' => $subcategoryName,
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    private function seedUsers(): array
    {
        $users = [];

        $users['seeker'] = User::updateOrCreate(
            ['email' => 'demo.seeker@skillbridge.test'],
            [
                'name' => 'Demo Seeker',
                'professional_title' => null,
                'role' => 'seeker',
                'phone' => '01700000001',
                'address' => 'Rangpur, Bangladesh',
                'lat' => 25.7439,
                'lng' => 89.2752,
                'nid_verified' => true,
                'rating' => 0,
                'bio' => 'Demo customer looking for reliable professional services.',
                'password' => Hash::make('password'),
                'moderation_status' => 'active',
            ]
        );

        $providers = [
            [
                'key' => 'laravel',
                'email' => 'demo.laravel@skillbridge.test',
                'name' => 'Rahim Ahmed',
                'title' => 'Laravel & Full Stack Developer',
                'phone' => '01700000002',
                'address' => 'Rangpur City, Bangladesh',
                'lat' => 25.7439,
                'lng' => 89.2752,
                'rating' => 4.90,
                'bio' => 'Experienced Laravel, PHP and React developer building business applications and APIs.',
            ],
            [
                'key' => 'electrician',
                'email' => 'demo.electrician@skillbridge.test',
                'name' => 'Karim Hasan',
                'title' => 'Certified Electrician',
                'phone' => '01700000003',
                'address' => 'Rangpur Sadar, Bangladesh',
                'lat' => 25.7460,
                'lng' => 89.2580,
                'rating' => 4.70,
                'bio' => 'Residential and commercial electrical installation, repair and troubleshooting.',
            ],
            [
                'key' => 'photographer',
                'email' => 'demo.photographer@skillbridge.test',
                'name' => 'Nusrat Jahan',
                'title' => 'Event Photographer & Videographer',
                'phone' => '01700000004',
                'address' => 'Rangpur City, Bangladesh',
                'lat' => 25.7500,
                'lng' => 89.2700,
                'rating' => 4.85,
                'bio' => 'Wedding, event and portrait photography with professional editing.',
            ],
            [
                'key' => 'makeup',
                'email' => 'demo.makeup@skillbridge.test',
                'name' => 'Mim Akter',
                'title' => 'Professional Makeup Artist',
                'phone' => '01700000005',
                'address' => 'Rangpur, Bangladesh',
                'lat' => 25.7400,
                'lng' => 89.2800,
                'rating' => 4.60,
                'bio' => 'Bridal, party and event makeup services with customized looks.',
            ],
            [
                'key' => 'tutor',
                'email' => 'demo.tutor@skillbridge.test',
                'name' => 'Sabbir Hossain',
                'title' => 'English & Academic Tutor',
                'phone' => '01700000006',
                'address' => 'Rangpur City, Bangladesh',
                'lat' => 25.7350,
                'lng' => 89.2650,
                'rating' => 4.75,
                'bio' => 'Academic and English tutor helping school and university students.',
            ],
        ];

        foreach ($providers as $provider) {
            $users[$provider['key']] = User::updateOrCreate(
                ['email' => $provider['email']],
                [
                    'name' => $provider['name'],
                    'professional_title' => $provider['title'],
                    'role' => 'provider',
                    'phone' => $provider['phone'],
                    'address' => $provider['address'],
                    'lat' => $provider['lat'],
                    'lng' => $provider['lng'],
                    'nid_verified' => true,
                    'rating' => $provider['rating'],
                    'bio' => $provider['bio'],
                    'password' => Hash::make('password'),
                    'moderation_status' => 'active',
                ]
            );
        }

        $users['admin'] = User::updateOrCreate(
            ['email' => 'demo.admin@skillbridge.test'],
            [
                'name' => 'SkillBridge Demo Admin',
                'professional_title' => 'Marketplace Administrator',
                'role' => 'admin',
                'phone' => '01700000007',
                'address' => 'Rangpur, Bangladesh',
                'nid_verified' => true,
                'rating' => 0,
                'bio' => 'Demo marketplace administrator.',
                'password' => Hash::make('password'),
                'moderation_status' => 'active',
            ]
        );

        return $users;
    }

    private function seedSkills(): array
    {
        $skillsByCategory = [
            'Technology' => [
                'Laravel',
                'PHP',
                'React',
                'JavaScript',
                'REST API Development',
                'MySQL',
                'Git',
                'Frontend Development',
            ],
            'Home & Repair' => [
                'Residential Wiring',
                'Electrical Troubleshooting',
                'Lighting Installation',
                'Plumbing Repair',
                'Pipe Installation',
                'AC Maintenance',
            ],
            'Events & Creative' => [
                'Event Photography',
                'Wedding Photography',
                'Videography',
                'Video Editing',
                'Adobe Premiere Pro',
            ],
            'Beauty' => [
                'Bridal Makeup',
                'Party Makeup',
                'Mehendi',
                'Hair Styling',
            ],
            'Education' => [
                'English Teaching',
                'Mathematics',
                'Academic Tutoring',
                'Spoken English',
            ],
        ];

        $skills = [];

        foreach ($skillsByCategory as $categoryName => $skillNames) {
            $category = Category::where('name', $categoryName)->first();

            foreach ($skillNames as $skillName) {
                $skill = Skill::updateOrCreate(
                    ['slug' => str($skillName)->slug()],
                    [
                        'name' => $skillName,
                        'category_id' => $category?->id,
                    ]
                );

                $skills[$skillName] = $skill;
            }
        }

        return $skills;
    }

    private function seedProviderProfiles(array $users, array $skills): void
    {
        $providerSkills = [
            'laravel' => [
                ['skill' => 'Laravel', 'years' => 6, 'rate' => 1200],
                ['skill' => 'PHP', 'years' => 7, 'rate' => 1100],
                ['skill' => 'React', 'years' => 4, 'rate' => 1000],
                ['skill' => 'MySQL', 'years' => 6, 'rate' => 900],
                ['skill' => 'REST API Development', 'years' => 5, 'rate' => 1000],
                ['skill' => 'Git', 'years' => 7, 'rate' => 700],
            ],
            'electrician' => [
                ['skill' => 'Residential Wiring', 'years' => 8, 'rate' => 700],
                ['skill' => 'Electrical Troubleshooting', 'years' => 7, 'rate' => 650],
                ['skill' => 'Lighting Installation', 'years' => 6, 'rate' => 600],
                ['skill' => 'AC Maintenance', 'years' => 4, 'rate' => 650],
            ],
            'photographer' => [
                ['skill' => 'Event Photography', 'years' => 7, 'rate' => 1800],
                ['skill' => 'Wedding Photography', 'years' => 6, 'rate' => 2000],
                ['skill' => 'Videography', 'years' => 5, 'rate' => 1800],
                ['skill' => 'Video Editing', 'years' => 5, 'rate' => 1500],
                ['skill' => 'Adobe Premiere Pro', 'years' => 4, 'rate' => 1400],
            ],
            'makeup' => [
                ['skill' => 'Bridal Makeup', 'years' => 6, 'rate' => 2500],
                ['skill' => 'Party Makeup', 'years' => 7, 'rate' => 1800],
                ['skill' => 'Mehendi', 'years' => 5, 'rate' => 1500],
                ['skill' => 'Hair Styling', 'years' => 4, 'rate' => 1200],
            ],
            'tutor' => [
                ['skill' => 'English Teaching', 'years' => 8, 'rate' => 800],
                ['skill' => 'Mathematics', 'years' => 7, 'rate' => 750],
                ['skill' => 'Academic Tutoring', 'years' => 9, 'rate' => 800],
                ['skill' => 'Spoken English', 'years' => 6, 'rate' => 700],
            ],
        ];

        foreach ($providerSkills as $providerKey => $items) {
            $provider = $users[$providerKey];

            foreach ($items as $item) {
                $skill = $skills[$item['skill']];

                $provider->skills()->syncWithoutDetaching([
                    $skill->id => [
                        'years_experience' => $item['years'],
                        'hourly_rate' => $item['rate'],
                    ],
                ]);
            }
        }

        $areas = [
            'laravel' => ['Rangpur City', 'Rangpur'],
            'electrician' => ['Rangpur Sadar', 'Rangpur'],
            'photographer' => ['Rangpur City', 'Rangpur'],
            'makeup' => ['Rangpur City', 'Rangpur'],
            'tutor' => ['Rangpur City', 'Rangpur'],
        ];

        foreach ($areas as $key => $areaNames) {
            foreach ($areaNames as $areaName) {
                ProviderServiceArea::updateOrCreate(
                    [
                        'provider_id' => $users[$key]->id,
                        'area_name' => $areaName,
                    ],
                    [
                        'city' => 'Rangpur',
                        'district' => 'Rangpur',
                        'postal_code' => '5400',
                        'latitude' => 25.7439,
                        'longitude' => 89.2752,
                        'radius_km' => 15,
                        'is_active' => true,
                    ]
                );
            }
        }

        foreach (['laravel', 'electrician', 'photographer', 'makeup', 'tutor'] as $key) {
            foreach ([1, 2, 3, 4, 5] as $day) {
                ProviderAvailability::updateOrCreate(
                    [
                        'provider_id' => $users[$key]->id,
                        'day_of_week' => $day,
                        'start_time' => '09:00:00',
                        'end_time' => '18:00:00',
                    ],
                    [
                        'is_active' => true,
                    ]
                );
            }
        }

        foreach (['laravel', 'electrician', 'photographer', 'makeup', 'tutor'] as $key) {
            ProviderVerification::updateOrCreate(
                ['user_id' => $users[$key]->id],
                [
                    'status' => 'verified',
                    'submitted_at' => now()->subDays(20),
                    'reviewed_at' => now()->subDays(18),
                    'reviewer_id' => $users['admin']->id,
                    'rejection_reason' => null,
                ]
            );
        }
    }

    private function seedJobs(array $users, array $skills): array
    {
        $technology = Category::where('name', 'Technology')->first();
        $homeRepair = Category::where('name', 'Home & Repair')->first();
        $events = Category::where('name', 'Events & Creative')->first();

        $laravelSub = Subcategory::where('category_id', $technology->id)
            ->where('name', 'Laravel Development')
            ->first();

        $electricalSub = Subcategory::where('category_id', $homeRepair->id)
            ->where('name', 'Electrical')
            ->first();

        $photoSub = Subcategory::where('category_id', $events->id)
            ->where('name', 'Photography')
            ->first();

        $jobs = [];

        $jobs['website'] = JobPost::updateOrCreate(
            [
                'user_id' => $users['seeker']->id,
                'title' => 'Build a Laravel and React Business Website',
            ],
            [
                'description' => 'Need a professional business website with Laravel API, React frontend, authentication and MySQL database.',
                'category' => 'Technology',
                'category_id' => $technology->id,
                'subcategory_id' => $laravelSub?->id,
                'budget_min' => 30000,
                'budget_max' => 50000,
                'status' => 'open',
                'location' => 'Rangpur City',
                'lat' => 25.7439,
                'lng' => 89.2752,
                'work_mode' => 'remote',
                'deadline' => now()->addDays(20)->toDateString(),
                'urgency' => 'normal',
                'preferred_experience' => '3+ years Laravel experience',
                'moderation_status' => 'visible',
            ]
        );

        $jobs['electrical'] = JobPost::updateOrCreate(
            [
                'user_id' => $users['seeker']->id,
                'title' => 'Residential Electrical Wiring and Lighting',
            ],
            [
                'description' => 'Need an experienced electrician for home wiring inspection, lighting installation and safety checks.',
                'category' => 'Home & Repair',
                'category_id' => $homeRepair->id,
                'subcategory_id' => $electricalSub?->id,
                'budget_min' => 8000,
                'budget_max' => 12000,
                'status' => 'open',
                'location' => 'Rangpur Sadar',
                'lat' => 25.7460,
                'lng' => 89.2580,
                'work_mode' => 'onsite',
                'deadline' => now()->addDays(7)->toDateString(),
                'urgency' => 'high',
                'preferred_experience' => '5+ years electrical experience',
                'moderation_status' => 'visible',
            ]
        );

        $jobs['photography'] = JobPost::updateOrCreate(
            [
                'user_id' => $users['seeker']->id,
                'title' => 'Wedding Photography for Family Event',
            ],
            [
                'description' => 'Looking for an event photographer for a wedding ceremony and reception in Rangpur.',
                'category' => 'Events & Creative',
                'category_id' => $events->id,
                'subcategory_id' => $photoSub?->id,
                'budget_min' => 18000,
                'budget_max' => 30000,
                'status' => 'completed',
                'location' => 'Rangpur City',
                'lat' => 25.7500,
                'lng' => 89.2700,
                'work_mode' => 'onsite',
                'deadline' => now()->subDays(10)->toDateString(),
                'urgency' => 'normal',
                'preferred_experience' => '4+ years event photography',
                'moderation_status' => 'visible',
            ]
        );

        $jobs['remote'] = JobPost::updateOrCreate(
            [
                'user_id' => $users['seeker']->id,
                'title' => 'React Frontend Bug Fixes',
            ],
            [
                'description' => 'Need help fixing responsive UI issues and API integration bugs in an existing React application.',
                'category' => 'Technology',
                'category_id' => $technology->id,
                'subcategory_id' => Subcategory::where('category_id', $technology->id)
                    ->where('name', 'React Development')
                    ->value('id'),
                'budget_min' => 6000,
                'budget_max' => 10000,
                'status' => 'in_progress',
                'location' => 'Remote',
                'work_mode' => 'remote',
                'deadline' => now()->addDays(5)->toDateString(),
                'urgency' => 'high',
                'preferred_experience' => '2+ years React experience',
                'moderation_status' => 'visible',
            ]
        );

        $jobs['website']->requiredSkills()->sync([
            $skills['Laravel']->id,
            $skills['PHP']->id,
            $skills['React']->id,
            $skills['MySQL']->id,
        ]);

        $jobs['electrical']->requiredSkills()->sync([
            $skills['Residential Wiring']->id,
            $skills['Electrical Troubleshooting']->id,
            $skills['Lighting Installation']->id,
        ]);

        $jobs['photography']->requiredSkills()->sync([
            $skills['Event Photography']->id,
            $skills['Wedding Photography']->id,
            $skills['Video Editing']->id,
        ]);

        $jobs['remote']->requiredSkills()->sync([
            $skills['React']->id,
            $skills['JavaScript']->id,
            $skills['Frontend Development']->id,
        ]);

        return $jobs;
    }

    private function seedBids(array $users, array $jobs): void
    {
        $bids = [
            [
                'job' => 'website',
                'provider' => 'laravel',
                'amount' => 42000,
                'status' => 'pending',
                'message' => 'I can build the Laravel API and React frontend with authentication and MySQL integration.',
            ],
            [
                'job' => 'website',
                'provider' => 'tutor',
                'amount' => 38000,
                'status' => 'rejected',
                'message' => 'I can help with the general project requirements.',
            ],
            [
                'job' => 'electrical',
                'provider' => 'electrician',
                'amount' => 9500,
                'status' => 'pending',
                'message' => 'I can inspect the wiring and complete the lighting installation safely.',
            ],
            [
                'job' => 'photography',
                'provider' => 'photographer',
                'amount' => 25000,
                'status' => 'accepted',
                'message' => 'I will cover the ceremony and reception with edited high-resolution photos.',
            ],
            [
                'job' => 'remote',
                'provider' => 'laravel',
                'amount' => 8500,
                'status' => 'accepted',
                'message' => 'I can fix the React UI and API integration issues.',
            ],
        ];

        foreach ($bids as $item) {
            Bid::updateOrCreate(
                [
                    'job_post_id' => $jobs[$item['job']]->id,
                    'provider_id' => $users[$item['provider']]->id,
                ],
                [
                    'amount' => $item['amount'],
                    'status' => $item['status'],
                    'message' => $item['message'],
                ]
            );
        }
    }

    private function seedContracts(array $users, array $jobs): array
    {
        $contracts = [];

        $photoBid = Bid::where('job_post_id', $jobs['photography']->id)
            ->where('provider_id', $users['photographer']->id)
            ->first();

        if ($photoBid) {
            $contracts['photo'] = Contract::updateOrCreate(
                ['bid_id' => $photoBid->id],
                [
                    'job_post_id' => $jobs['photography']->id,
                    'provider_id' => $users['photographer']->id,
                    'seeker_id' => $users['seeker']->id,
                    'amount' => $photoBid->amount,
                    'status' => 'completed',
                    'started_at' => now()->subDays(20),
                    'completed_at' => now()->subDays(10),
                ]
            );
        }

        $remoteBid = Bid::where('job_post_id', $jobs['remote']->id)
            ->where('provider_id', $users['laravel']->id)
            ->first();

        if ($remoteBid) {
            $contracts['remote'] = Contract::updateOrCreate(
                ['bid_id' => $remoteBid->id],
                [
                    'job_post_id' => $jobs['remote']->id,
                    'provider_id' => $users['laravel']->id,
                    'seeker_id' => $users['seeker']->id,
                    'amount' => $remoteBid->amount,
                    'status' => 'active',
                    'started_at' => now()->subDays(2),
                    'completed_at' => null,
                ]
            );
        }

        foreach ($contracts as $key => $contract) {
            ContractMilestone::updateOrCreate(
                [
                    'contract_id' => $contract->id,
                    'title' => $key === 'photo'
                        ? 'Final photo delivery'
                        : 'Frontend implementation',
                ],
                [
                    'description' => $key === 'photo'
                        ? 'Deliver edited event photographs.'
                        : 'Complete React UI and API integration fixes.',
                    'amount' => $contract->amount,
                    'due_date' => $key === 'photo'
                        ? now()->subDays(10)->toDateString()
                        : now()->addDays(5)->toDateString(),
                    'status' => $key === 'photo' ? 'approved' : 'in_progress',
                    'submitted_at' => $key === 'photo' ? now()->subDays(11) : null,
                    'approved_at' => $key === 'photo' ? now()->subDays(10) : null,
                ]
            );
        }

        return $contracts;
    }

    private function seedPayments(array $users, array $contracts): void
    {
        foreach ($contracts as $key => $contract) {
            $amount = (float) $contract->amount;
            $fee = round($amount * 0.05, 2);

            $deposit = Transaction::updateOrCreate(
                [
                    'contract_id' => $contract->id,
                    'type' => 'deposit',
                ],
                [
                    'payer_id' => $users['seeker']->id,
                    'payee_id' => $users[$key === 'photo' ? 'photographer' : 'laravel']->id,
                    'amount' => $amount,
                    'platform_fee' => $fee,
                    'status' => $key === 'photo' ? 'released' : 'held',
                ]
            );

            $payment = PaymentTransaction::updateOrCreate(
                [
                    'idempotency_key' => "demo-{$key}-deposit-v1",
                ],
                [
                    'contract_id' => $contract->id,
                    'transaction_id' => $deposit->id,
                    'milestone_id' => $contract->milestones()->first()?->id,
                    'provider' => 'demo_gateway',
                    'provider_payment_id' => "demo-payment-{$contract->id}",
                    'provider_event_id' => "demo-event-{$contract->id}",
                    'amount' => $amount,
                    'currency' => 'BDT',
                    'status' => 'succeeded',
                    'paid_at' => now()->subDays(3),
                ]
            );

            $escrow = EscrowAccount::updateOrCreate(
                ['contract_id' => $contract->id],
                [
                    'currency' => 'BDT',
                    'balance' => $key === 'photo' ? 0 : $amount,
                    'status' => $key === 'photo' ? 'closed' : 'open',
                ]
            );

            EscrowEntry::updateOrCreate(
                [
                    'idempotency_key' => "demo-{$key}-hold-v1",
                ],
                [
                    'escrow_account_id' => $escrow->id,
                    'contract_id' => $contract->id,
                    'payment_transaction_id' => $payment->id,
                    'milestone_id' => $contract->milestones()->first()?->id,
                    'transaction_id' => $deposit->id,
                    'amount' => $amount,
                    'type' => 'hold',
                    'status' => $key === 'photo' ? 'released' : 'held',
                    'metadata' => [
                        'source' => 'demo_seeder',
                    ],
                ]
            );

            if ($key === 'photo') {
                Transaction::updateOrCreate(
                    [
                        'contract_id' => $contract->id,
                        'type' => 'release',
                    ],
                    [
                        'payer_id' => $users['seeker']->id,
                        'payee_id' => $users['photographer']->id,
                        'amount' => $amount,
                        'platform_fee' => $fee,
                        'status' => 'released',
                    ]
                );

                EscrowEntry::updateOrCreate(
                    [
                        'idempotency_key' => "demo-{$key}-release-v1",
                    ],
                    [
                        'escrow_account_id' => $escrow->id,
                        'contract_id' => $contract->id,
                        'payment_transaction_id' => $payment->id,
                        'milestone_id' => $contract->milestones()->first()?->id,
                        'transaction_id' => $deposit->id,
                        'amount' => $amount,
                        'type' => 'release',
                        'status' => 'released',
                        'metadata' => [
                            'source' => 'demo_seeder',
                        ],
                    ]
                );
            }
        }
    }

    private function seedReviews(array $users, array $contracts): void
    {
        if (!isset($contracts['photo'])) {
            return;
        }

        Review::updateOrCreate(
            [
                'reviewer_id' => $users['seeker']->id,
                'contract_id' => $contracts['photo']->id,
                'reviewee_id' => $users['photographer']->id,
            ],
            [
                'rating' => 5,
                'comment' => 'Excellent photography service. Professional, punctual and delivered high-quality edited photos.',
                'sentiment_label' => 'positive',
                'status' => 'published',
                'moderation_status' => 'visible',
            ]
        );
    }

    private function seedServices(array $users): void
    {
        $definitions = [
            [
                'provider' => 'laravel',
                'category' => 'Technology',
                'subcategory' => 'Laravel Development',
                'title' => 'Laravel API Development',
                'slug' => 'demo-laravel-api-development',
                'description' => 'Professional Laravel API development for business applications.',
                'price' => 15000,
            ],
            [
                'provider' => 'electrician',
                'category' => 'Home & Repair',
                'subcategory' => 'Electrical',
                'title' => 'Home Electrical Repair',
                'slug' => 'demo-home-electrical-repair',
                'description' => 'Electrical inspection, repair and lighting installation.',
                'price' => 5000,
            ],
            [
                'provider' => 'photographer',
                'category' => 'Events & Creative',
                'subcategory' => 'Photography',
                'title' => 'Wedding Photography Package',
                'slug' => 'demo-wedding-photography',
                'description' => 'Wedding and event photography with edited digital delivery.',
                'price' => 20000,
            ],
            [
                'provider' => 'makeup',
                'category' => 'Beauty',
                'subcategory' => 'Makeup',
                'title' => 'Bridal Makeup Service',
                'slug' => 'demo-bridal-makeup',
                'description' => 'Professional bridal makeup with customized styling.',
                'price' => 8000,
            ],
            [
                'provider' => 'tutor',
                'category' => 'Education',
                'subcategory' => 'English Tutor',
                'title' => 'English Speaking & Academic Tutoring',
                'slug' => 'demo-english-tutoring',
                'description' => 'Personalized English and academic tutoring.',
                'price' => 3000,
            ],
        ];

        foreach ($definitions as $item) {
            $category = Category::where('name', $item['category'])->first();
            $subcategory = Subcategory::where('category_id', $category->id)
                ->where('name', $item['subcategory'])
                ->first();

            $service = Service::updateOrCreate(
                ['slug' => $item['slug']],
                [
                    'provider_id' => $users[$item['provider']]->id,
                    'category_id' => $category->id,
                    'subcategory_id' => $subcategory->id,
                    'title' => $item['title'],
                    'description' => $item['description'],
                    'status' => 'published',
                    'moderation_status' => 'visible',
                ]
            );

            ServicePackage::updateOrCreate(
                [
                    'service_id' => $service->id,
                    'name' => 'Standard Package',
                ],
                [
                    'description' => $item['description'],
                    'price' => $item['price'],
                    'delivery_days' => 7,
                    'revisions' => 2,
                    'sort_order' => 1,
                ]
            );
        }
    }

    private function seedPortfolios(array $users): void
    {
        $items = [
            ['laravel', 'Laravel Business API Project'],
            ['electrician', 'Residential Wiring Project'],
            ['photographer', 'Wedding Photography Project'],
            ['makeup', 'Bridal Makeup Portfolio'],
            ['tutor', 'English Tutoring Program'],
        ];

        foreach ($items as [$provider, $title]) {
            PortfolioItem::updateOrCreate(
                [
                    'provider_id' => $users[$provider]->id,
                    'title' => $title,
                ],
                [
                    'description' => "Demo portfolio item for {$users[$provider]->professional_title}.",
                    'sort_order' => 1,
                    'status' => 'published',
                    'moderation_status' => 'visible',
                ]
            );
        }
    }

    private function seedSavedJobsAndFavorites(array $users, array $jobs): void
    {
        SavedJob::updateOrCreate(
            [
                'user_id' => $users['seeker']->id,
                'job_post_id' => $jobs['website']->id,
            ],
            []
        );

        foreach (['laravel', 'electrician', 'photographer'] as $provider) {
            Favorite::updateOrCreate(
                [
                    'user_id' => $users['seeker']->id,
                    'target_type' => 'provider',
                    'target_id' => $users[$provider]->id,
                ],
                []
            );
        }
    }

    private function seedMessaging(array $users): void
    {
        $pairs = [
            ['seeker', 'laravel'],
            ['seeker', 'photographer'],
            ['seeker', 'electrician'],
        ];

        foreach ($pairs as [$a, $b]) {
            $conversation = Conversation::findOrCreateBetween(
                $users[$a]->id,
                $users[$b]->id
            );

            $messages = [
                [$a, "Hello, I would like to discuss the project requirements."],
                [$b, "Sure. Please share the details and expected timeline."],
                [$a, "The project should be completed within the agreed deadline."],
            ];

            foreach ($messages as [$sender, $body]) {
                ConversationMessage::firstOrCreate(
                    [
                        'conversation_id' => $conversation->id,
                        'sender_id' => $users[$sender]->id,
                        'body' => $body,
                    ],
                    [
                        'read_at' => null,
                    ]
                );
            }
        }
    }

    private function seedNotifications(array $users): void
    {
        Notification::createOnce(
            $users['seeker']->id,
            'demo.bid_received',
            'New bid received',
            'A provider submitted a bid on your job.',
            ['source' => 'demo_seeder']
        );

        Notification::createOnce(
            $users['laravel']->id,
            'demo.contract_active',
            'Contract active',
            'Your demo contract is currently active.',
            ['source' => 'demo_seeder']
        );
    }

    private function seedDisputes(array $users, array $contracts): void
    {
        if (!isset($contracts['photo'])) {
            return;
        }

        Dispute::updateOrCreate(
            [
                'active_scope_key' => 'demo-resolved-photo-dispute',
            ],
            [
                'contract_id' => $contracts['photo']->id,
                'raised_by' => $users['seeker']->id,
                'against_user_id' => $users['photographer']->id,
                'milestone_id' => $contracts['photo']->milestones()->first()?->id,
                'reason' => 'Demo resolution record',
                'description' => 'Historical demo dispute used for testing dispute history and resolution views.',
                'status' => 'resolved',
                'resolution' => 'Resolved in favor of completed delivery.',
                'resolved_by' => $users['admin']->id,
                'resolved_at' => now()->subDays(8),
            ]
        );
    }

    private function seedAdminAuditLogs(array $users): void
    {
        DB::table('admin_audit_logs')->updateOrInsert(
            [
                'admin_user_id' => $users['admin']->id,
                'action' => 'demo_seed',
                'target_type' => 'demo_dataset',
                'target_id' => 1,
            ],
            [
                'reason' => 'Demo dataset initialization.',
                'metadata' => json_encode([
                    'source' => 'DemoDataSeeder',
                    'version' => 1,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
