<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\BlogPost;
use App\Models\Donation;
use App\Models\Event;
use App\Models\Inquiry;
use App\Models\MediaItem;
use App\Models\DigitalPortfolioItem;
use App\Models\DigitalSolution;
use App\Models\TicketType;
use App\Models\Volunteer;
use Illuminate\Database\Seeder;

/**
 * Generic demo fixtures: idempotent guards, placeholder content only.
 */
class RoiSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Global Admin Account (env hash stored verbatim — no re-hashing).
        if (!AdminUser::where('email', config('roi.admin_email'))->exists()) {
            AdminUser::create([
                'email' => config('roi.admin_email'),
                'password_hash' => config('roi.admin_password_hash'),
                'last_login' => now(),
            ]);
        }

        // 2. Flagship & Upcoming Events
        if (Event::count() === 0) {
            Event::insert([
                [
                    'title' => 'Youth Leadership Summit Annual Conference 2026',
                    'date' => 'August 14-16, 2026',
                    'time' => '08:30 AM - 05:00 PM EAT',
                    'location' => 'Community Hub Amphitheater, Harbor City, Kenya',
                    'description' => 'Our flagship youth empowerment conference convening over 500 young leaders across the coast. Focusing on ethical leadership, digital entrepreneurship, mental resilience, and community transformation.',
                    'category' => 'Flagship Conference',
                    'image_url' => 'https://images.unsplash.com/photo-1511578314322-379afb476865?auto=format&fit=crop&w=800&q=80',
                    'is_active' => true,
                ],
                [
                    'title' => 'Coastal Youth Tech & Mentorship Bootcamp',
                    'date' => 'July 10, 2026',
                    'time' => '09:00 AM - 03:00 PM EAT',
                    'location' => 'DEMO Youth Center, Riverside, Harbor City',
                    'description' => 'An intensive hands-on digital skills workshop preparing vulnerable youth for remote jobs and coding careers. Mentors include seasoned software engineers and local entrepreneurs.',
                    'category' => 'Workshop',
                    'image_url' => 'https://images.unsplash.com/photo-1531482615713-2afd69097998?auto=format&fit=crop&w=800&q=80',
                    'is_active' => true,
                ],
                [
                    'title' => 'Clean Harbor City Community Environment Drive',
                    'date' => 'June 30, 2026',
                    'time' => '07:00 AM - 11:30 AM EAT',
                    'location' => 'Bayview Beach & Southside Crossing, Harbor City',
                    'description' => 'Mobilizing over 150 DEMO volunteers to restore coastal marine habitats, collect plastic waste, and educate local market vendors on sustainable waste disposal.',
                    'category' => 'Outreach',
                    'image_url' => 'https://images.unsplash.com/photo-1542601906990-b4d3fb778b09?auto=format&fit=crop&w=800&q=80',
                    'is_active' => true,
                ],
            ]);
        }

        // 3. Blog Posts
        if (BlogPost::count() === 0) {
            BlogPost::insert([
                [
                    'title' => 'Empowering Harbor City Youth: The Journey of Youth Leadership Summit',
                    'slug' => 'empowering-harbor-city-youth-leadership-summit',
                    'summary' => 'Discover how our annual flagship conference has transformed the lives of over 1,000 young individuals along the coast through mentorship and ethical grounding.',
                    'content' => "The coastal region of Harbor City represents immense potential, yet young people frequently encounter systemic hurdles ranging from unemployment to social vulnerability. The Demo NGO (DEMO) established the **Youth Leadership Summit** conference to bridge this gap.\n\nThrough rigorous mentorship cohorts, skills development sessions, and direct access to industry pioneers, participants gain confidence and marketable competencies. In our 2025 impact assessment, 84% of attendees reported launching community micro-initiatives or securing gainful employment within six months.\n\nAs we gear up for the 2026 edition at the community hub, our vision remains resolute: building a morally upright, economically self-reliant generation that leads Kenya into a brighter future.",
                    'category' => 'Mentorship',
                    'author' => 'Fatuma Bakari - Lead Coordinator',
                    'image_url' => 'https://images.unsplash.com/photo-1529156069898-49953e39b3ac?auto=format&fit=crop&w=800&q=80',
                    'is_published' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'title' => 'Bridging the Digital Divide: Tech Literacy in Riverside & Northside',
                    'slug' => 'bridging-the-digital-divide-tech-literacy',
                    'summary' => "How DEMO's mobile computer labs are bringing coding, graphic design, and online freelancing skills to underserved informal settlements.",
                    'content' => "Access to a laptop and reliable broadband is no longer a luxury—it is the baseline for economic survival in the 21st century. In partnership with local well-wishers and international donors, DEMO launched the Mobile Digital Empowerment Hub.\n\nEvery weekend, our volunteer trainers deploy to community halls across Northside and Southside. Young students learn Python fundamentals, web design, and digital marketing. The results speak for themselves: several alumni are now earning foreign currency through international remote platforms, injecting capital directly into their households.",
                    'category' => 'Education',
                    'author' => 'Hamisi Hassan - Tech Lead',
                    'image_url' => 'https://images.unsplash.com/photo-1509062522246-3755977927d7?auto=format&fit=crop&w=800&q=80',
                    'is_published' => true,
                    'created_at' => now()->subMinutes(1),
                    'updated_at' => now()->subMinutes(1),
                ],
                [
                    'title' => 'Community Voices: Meet Ali, Transformed by Mentorship',
                    'slug' => 'community-voices-meet-ali-transformed',
                    'summary' => 'A heartwarming case study of resilience, determination, and the ripple effect of supportive community networks in Harbor City.',
                    'content' => "When Ali first joined the Demo NGO mentorship circle in 2023, he was facing severe hardship after finishing high school without financial support for university.\n\nMatched with a DEMO mentor in logistics and trade, Ali underwent our 12-week character and career readiness program. Today, Ali oversees supply chain coordination at a thriving Harbor City port enterprise and actively funds tuition for two younger girls in his neighborhood. His story illustrates the ripple effect of sustained mentorship.",
                    'category' => 'Success Stories',
                    'author' => 'DEMO Media Team',
                    'image_url' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=800&q=80',
                    'is_published' => true,
                    'created_at' => now()->subMinutes(2),
                    'updated_at' => now()->subMinutes(2),
                ],
            ]);
        }

        // 4. Demo Media Hub (DEMO TV) Videos — placeholder catalogue, no live channel linkage
        if (MediaItem::count() === 0) {
            MediaItem::insert([
                [
                    'youtube_id' => 'demoVideo001',
                    'title' => 'Community Iftar Distribution | Feeding 70 Local Families',
                    'category' => 'Community Outreach',
                    'summary' => 'A neighbourhood iftar distribution programme feeding 70 families, captured by our volunteer media desk.',
                    'thumbnail_url' => 'https://i.ytimg.com/vi/demoVideo001/hqdefault.jpg',
                    'duration' => '9:28',
                    'is_featured' => true,
                    'published_at' => '2026-03-17 14:30:18',
                ],
                [
                    'youtube_id' => 'demoVideo002',
                    'title' => 'Books Before Comfort — The Value of a Educated Community || Youth Leadership Summit Vol 2',
                    'category' => 'Events & Conferences',
                    'summary' => 'Youth Leadership Summit Vol 2: an inspiring session on seeking knowledge and building a capable community. Part of our flagship youth empowerment conference series.',
                    'thumbnail_url' => 'https://i.ytimg.com/vi/demoVideo002/hqdefault.jpg',
                    'duration' => '41:34',
                    'is_featured' => false,
                    'published_at' => '2026-01-02 16:15:03',
                ],
                [
                    'youtube_id' => 'demoVideo003',
                    'title' => 'Youth Leadership Summit Vol. 2 || The Return You\'ve All Been Waiting For',
                    'category' => 'Events & Conferences',
                    'summary' => 'The return of our flagship youth empowerment conference! Youth Leadership Summit Vol. 2 brings together mentors, leaders, and youth for transformative sessions on character, purpose, and community impact.',
                    'thumbnail_url' => 'https://i.ytimg.com/vi/demoVideo003/hqdefault.jpg',
                    'duration' => '1:42',
                    'is_featured' => false,
                    'published_at' => '2025-11-14 11:00:06',
                ],
            ]);
        }

        // 5. Volunteers
        if (Volunteer::count() === 0) {
            Volunteer::insert([
                [
                    'full_name' => 'Salim Omari',
                    'email' => 'salim.omari@gmail.com',
                    'phone' => '+254 712 345 678',
                    'primary_skill' => 'Event Coordination',
                    'availability' => 'Weekends, Youth Leadership Summit Conference',
                    'motivation' => 'Passionate about giving back to Harbor City youth and organizing community events.',
                    'status' => 'Approved',
                    'created_at' => now(),
                ],
                [
                    'full_name' => 'Grace Achieng',
                    'email' => 'grace.a@yahoo.com',
                    'phone' => '+254 722 987 654',
                    'primary_skill' => 'Graphic Design',
                    'availability' => 'Weekdays (Remote)',
                    'motivation' => 'I want to design digital flyers and social media campaigns for DEMO programs.',
                    'status' => 'Approved',
                    'created_at' => now(),
                ],
                [
                    'full_name' => 'Juma Mwangi',
                    'email' => 'juma.m@outlook.com',
                    'phone' => '+254 733 112 233',
                    'primary_skill' => 'Mentorship & Teaching',
                    'availability' => 'Weekends',
                    'motivation' => 'Eager to mentor high school students in math and career guidance.',
                    'status' => 'Pending Review',
                    'created_at' => now(),
                ],
            ]);
        }

        // 6. Donations
        if (Donation::count() === 0) {
            Donation::insert([
                [
                    'donor_name' => 'David K.', 'email' => 'david@wellwisher.org', 'amount' => 10000.0,
                    'currency' => 'KES', 'gateway' => 'M-Pesa Bank Partner', 'frequency' => 'one-time',
                    'reference' => 'DEMO-MPE-98A7B6C1', 'status' => 'Completed', 'created_at' => now(),
                ],
                [
                    'donor_name' => 'Sarah Jenkins', 'email' => 's.jenkins@london.uk', 'amount' => 50.0,
                    'currency' => 'GBP', 'gateway' => 'Stripe', 'frequency' => 'monthly',
                    'reference' => 'DEMO-STR-44E21F89', 'status' => 'Completed', 'created_at' => now(),
                ],
                [
                    'donor_name' => 'Regional Foundation Match', 'email' => 'csr@partnerfoundation.example', 'amount' => 50000.0,
                    'currency' => 'KES', 'gateway' => 'M-Pesa Foundation Match', 'frequency' => 'one-time',
                    'reference' => 'DEMO-MPE-11X99Y88', 'status' => 'Completed', 'created_at' => now(),
                ],
                [
                    'donor_name' => 'Dr. Amina Patel', 'email' => 'apatel@regionaltrust.example', 'amount' => 100.0,
                    'currency' => 'USD', 'gateway' => 'Paystack', 'frequency' => 'one-time',
                    'reference' => 'DEMO-PAY-77B33C22', 'status' => 'Completed', 'created_at' => now(),
                ],
            ]);
        }

        // 7. Inquiries
        if (Inquiry::count() === 0) {
            Inquiry::insert([
                [
                    'name' => 'Partnership Desk - Community Hub',
                    'email' => 'info@communityhub.co.example',
                    'subject' => 'Conference Venue Sponsorship',
                    'message' => 'We would like to confirm technical sound support for the upcoming Youth Leadership Summit conference.',
                    'status' => 'New',
                    'created_at' => now(),
                ],
                [
                    'name' => 'Kelvin Musyoka',
                    'email' => 'kelvin@student.example',
                    'subject' => 'Bootcamp Enrollment',
                    'message' => 'Hello, how can I register for the July coding bootcamp? Are there any equipment prerequisites?',
                    'status' => 'New',
                    'created_at' => now(),
                ],
            ]);
        }

        $this->seedTicketTypes();
        $this->seedDigitalSolutions();
        $this->seedDigitalPortfolio();
    }

    protected function seedTicketTypes(): void
    {
        if (TicketType::count() > 0) {
            return;
        }

        $flagship = Event::where('title', 'like', 'Youth Leadership Summit%')->first();
        $bootcamp = Event::where('title', 'like', 'Coastal Youth Tech%')->first();
        if (!$flagship || !$bootcamp) {
            return;
        }

        TicketType::insert([
            [
                'event_id' => $flagship->id,
                'name' => 'Youth Delegate',
                'description' => 'Full access to Youth Leadership Summit sessions, lunch, and a digital badge.',
                'price' => 500,
                'currency' => 'KES',
                'quantity' => 400,
                'sold_count' => 0,
                'is_active' => true,
                'max_per_order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'event_id' => $flagship->id,
                'name' => 'Standard Seat',
                'description' => 'General admission for community members and guests.',
                'price' => 1500,
                'currency' => 'KES',
                'quantity' => 80,
                'sold_count' => 0,
                'is_active' => true,
                'max_per_order' => 6,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'event_id' => $flagship->id,
                'name' => 'VIP Patron',
                'description' => 'Front-row seating, networking lounge, and printed programme.',
                'price' => 5000,
                'currency' => 'KES',
                'quantity' => 20,
                'sold_count' => 0,
                'is_active' => true,
                'max_per_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'event_id' => $bootcamp->id,
                'name' => 'Free Workshop Pass',
                'description' => 'Complimentary seat for the Riverside tech & mentorship bootcamp.',
                'price' => 0,
                'currency' => 'KES',
                'quantity' => 60,
                'sold_count' => 0,
                'is_active' => true,
                'max_per_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    protected function seedDigitalSolutions(): void
    {
        if (DigitalSolution::count() > 0) {
            return;
        }

        DigitalSolution::insert([
            [
                'title' => 'Community Event Ticketing',
                'slug' => 'community-event-ticketing',
                'category' => 'Platform',
                'summary' => 'QR-ready door system, M-Pesa checkout, and a recovery portal for CBOs.',
                'description' => 'We install the same stack that runs Youth Leadership Summit: reserved inventory, Paystack/M-Pesa, email passes, and a gate check-in desk.',
                'price_label' => 'From KES 25,000',
                'icon' => 'ticket',
                'is_published' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title' => 'Youth Digital Lab',
                'slug' => 'youth-digital-lab',
                'category' => 'Education',
                'summary' => 'Weekend labs that teach coding, design, and remote-work basics in Riverside and Northside.',
                'description' => 'Curriculum, volunteer trainers, and a lightweight attendance portal so partners can run their own digital literacy weekends.',
                'price_label' => 'Cohort pricing',
                'icon' => 'laptop',
                'is_published' => true,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title' => 'Demo Media Studio',
                'slug' => 'demo-media-studio',
                'category' => 'Media',
                'summary' => 'Livestream and documentary production for coastal youth programmes.',
                'description' => 'Camera kit, YouTube publishing workflow, and a volunteer desk that matches the DEMO Media Hub.',
                'price_label' => 'Day rate',
                'icon' => 'video',
                'is_published' => true,
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    protected function seedDigitalPortfolio(): void
    {
        if (DigitalPortfolioItem::count() > 0) {
            return;
        }

        $ticketing = DigitalSolution::where('slug', 'community-event-ticketing')->first();
        $lab = DigitalSolution::where('slug', 'youth-digital-lab')->first();

        DigitalPortfolioItem::insert([
            [
                'digital_solution_id' => $ticketing?->id,
                'title' => 'Youth Leadership Summit 2025 door system',
                'slug' => 'youth-leadership-summit-2025-door-system',
                'client' => 'Demo NGO',
                'location' => 'the community hub, Harbor City',
                'year' => '2025',
                'summary' => 'Hard-reserved inventory and QR check-in for the flagship youth conference.',
                'outcome' => '400+ youth delegates admitted without a paper list.',
                'image_url' => 'https://images.unsplash.com/photo-1511578314322-379afb476865?auto=format&fit=crop&w=800&q=80',
                'is_published' => true,
                'is_featured' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'digital_solution_id' => $lab?->id,
                'title' => 'Riverside weekend digital lab',
                'slug' => 'riverside-weekend-digital-lab',
                'client' => 'DEMO Youth Center',
                'location' => 'Riverside, Harbor City',
                'year' => '2026',
                'summary' => 'A six-week Python and web-design cohort for informal-settlement youth.',
                'outcome' => 'Alumni began remote freelance work within a term.',
                'image_url' => 'https://images.unsplash.com/photo-1531482615713-2afd69097998?auto=format&fit=crop&w=800&q=80',
                'is_published' => true,
                'is_featured' => false,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
