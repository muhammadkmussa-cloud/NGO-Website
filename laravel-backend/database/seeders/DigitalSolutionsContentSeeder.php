<?php

namespace Database\Seeders;

use App\Models\DigitalSolution;
use App\Models\DigitalSolutionFaq;
use App\Models\DigitalSolutionIndustry;
use App\Models\DigitalSolutionTech;
use App\Models\DigitalPortfolioItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DigitalSolutionsContentSeeder extends Seeder
{
    public function run(): void
    {
        // 15 Service Categories
        $services = [
            [
                'title' => 'Websites and Digital Presence',
                'service_category' => 'Websites and Digital Presence',
                'category' => 'Digital Presence',
                'summary' => 'Modern, accessible websites that tell your story and convert visitors.',
                'description' => 'We build responsive, SEO-ready websites using Laravel and Tailwind CSS. From simple landing pages to multi-section sites with CMS-driven content, every build includes analytics, search optimization, and mobile-first design.',
                'features' => ['Responsive design', 'SEO optimization', 'CMS integration', 'Analytics setup', 'Accessibility (WCAG 2.1 AA)'],
                'price_label' => 'From KES 45,000',
                'icon' => 'globe',
                'is_published' => true,
                'sort_order' => 1,
            ],
            [
                'title' => 'Web Applications',
                'service_category' => 'Web Applications',
                'category' => 'Custom Development',
                'summary' => 'Custom browser-based tools that automate workflows and centralize data.',
                'description' => 'Full-stack web applications built with Laravel, Vue/Alpine, and MySQL. We handle auth, role-based access, API integrations, and real-time features. Ideal for dashboards, portals, and internal tools.',
                'features' => ['Multi-tenant ready', 'Role-based access', 'API-first', 'Real-time updates', 'Audit logging'],
                'price_label' => 'From KES 120,000',
                'icon' => 'layout',
                'is_published' => true,
                'sort_order' => 2,
            ],
            [
                'title' => 'Mobile Applications',
                'service_category' => 'Mobile Applications',
                'category' => 'Custom Development',
                'summary' => 'Native and cross-platform apps for iOS and Android.',
                'description' => 'We build mobile apps using Flutter or React Native with Laravel backends. Offline-first architecture, push notifications, biometric auth, and app store deployment included.',
                'features' => ['iOS & Android', 'Offline sync', 'Push notifications', 'Biometric auth', 'App store submission'],
                'price_label' => 'From KES 180,000',
                'icon' => 'smartphone',
                'is_published' => true,
                'sort_order' => 3,
            ],
            [
                'title' => 'Business Management Systems',
                'service_category' => 'Business Management Systems',
                'category' => 'Custom Development',
                'summary' => 'ERP-style platforms for finance, HR, inventory, and operations.',
                'description' => 'Modular business systems that replace spreadsheets and disjointed tools. Built on Laravel with multi-company support, approval workflows, and customizable reports.',
                'features' => ['Multi-company', 'Approval workflows', 'Custom reports', 'Data import/export', 'Audit trails'],
                'price_label' => 'From KES 200,000',
                'icon' => 'database',
                'is_published' => true,
                'sort_order' => 4,
            ],
            [
                'title' => 'School Management Systems',
                'service_category' => 'School Management Systems',
                'category' => 'Education',
                'summary' => 'Complete platforms for student records, fees, timetables, and parent portals.',
                'description' => 'Tailored for Kenyan curriculum schools. Handles admissions, grading (CBC/8-4-4), M-Pesa fee collection, SMS notifications, and government reporting (NEMIS).',
                'features' => ['CBC & 8-4-4 grading', 'M-Pesa fee collection', 'Parent/student portals', 'SMS/email alerts', 'NEMIS export'],
                'price_label' => 'From KES 150,000',
                'icon' => 'graduation-cap',
                'is_published' => true,
                'sort_order' => 5,
            ],
            [
                'title' => 'Library Management Systems',
                'service_category' => 'Library Management Systems',
                'category' => 'Education',
                'summary' => 'Catalog, circulation, and patron management for school and community libraries.',
                'description' => 'Barcode-based check-in/out, overdue tracking, MARC import, digital resource linking, and patron self-service. Works offline-first for unreliable connectivity.',
                'features' => ['Barcode scanning', 'Overdue automation', 'MARC import', 'Digital resources', 'Offline capable'],
                'price_label' => 'From KES 80,000',
                'icon' => 'book',
                'is_published' => true,
                'sort_order' => 6,
            ],
            [
                'title' => 'NGO/Community Management Systems',
                'service_category' => 'NGO/Community Management Systems',
                'category' => 'Non-Profit',
                'summary' => 'Beneficiary tracking, donor reporting, volunteer coordination, and grant compliance.',
                'description' => 'Built for Kenyan NGOs and CBOs. Case management, outcome tracking, donor dashboards, and compliance exports (USAID, EU, local). Role-based access for field staff.',
                'features' => ['Case management', 'Donor dashboards', 'Grant compliance', 'Field staff access', 'Data export'],
                'price_label' => 'From KES 120,000',
                'icon' => 'users',
                'is_published' => true,
                'sort_order' => 7,
            ],
            [
                'title' => 'E-commerce and Marketplaces',
                'service_category' => 'E-commerce and Marketplaces',
                'category' => 'Commerce',
                'summary' => 'Online stores and multi-vendor platforms with M-Pesa and card payments.',
                'description' => 'Laravel-based shops with product variants, inventory sync, coupon engine, abandoned cart recovery, and Daraja/Paystack integration. Multi-vendor marketplace option available.',
                'features' => ['M-Pesa & card', 'Inventory sync', 'Coupons & promos', 'Multi-vendor ready', 'Order tracking'],
                'price_label' => 'From KES 100,000',
                'icon' => 'shopping-cart',
                'is_published' => true,
                'sort_order' => 8,
            ],
            [
                'title' => 'Event/Booking/Ticketing Systems',
                'service_category' => 'Event/Booking/Ticketing Systems',
                'category' => 'Events',
                'summary' => 'End-to-end event platforms with QR check-in, waitlists, and recovery portals.',
                'description' => 'The same stack powering Youth Leadership Summit. Reserved seating, group bookings, M-Pesa/Paystack checkout, email/SMS passes, gate manager apps, and real-time analytics.',
                'features' => ['QR check-in', 'Waitlists', 'M-Pesa/Paystack', 'Recovery portal', 'Gate manager app'],
                'price_label' => 'From KES 60,000',
                'icon' => 'calendar',
                'is_published' => true,
                'sort_order' => 9,
            ],
            [
                'title' => 'Inventory/POS Systems',
                'service_category' => 'Inventory/POS Systems',
                'category' => 'Commerce',
                'summary' => 'Stock control, barcode scanning, and point-of-sale for retail and wholesale.',
                'description' => 'Real-time inventory across locations, purchase orders, low-stock alerts, barcode label printing, and offline-capable POS terminal. Integrates with M-Pesa for payments.',
                'features' => ['Multi-location', 'Barcode labels', 'Low-stock alerts', 'Offline POS', 'M-Pesa payments'],
                'price_label' => 'From KES 90,000',
                'icon' => 'box',
                'is_published' => true,
                'sort_order' => 10,
            ],
            [
                'title' => 'Payment Integrations (M-Pesa, Cards)',
                'service_category' => 'Payment Integrations (M-Pesa, Cards)',
                'category' => 'Integrations',
                'summary' => 'Daraja STK Push, Paystack, and custom payment flows for any Laravel app.',
                'description' => 'We integrate Safaricom Daraja (STK Push, B2C, B2B, Reversal), Paystack, and Stripe. Includes webhook handling, idempotency, reconciliation reports, and PCI-aware tokenization.',
                'features' => ['Daraja STK/B2C/B2B', 'Paystack/Stripe', 'Webhook handling', 'Idempotency keys', 'Reconciliation reports'],
                'price_label' => 'From KES 40,000',
                'icon' => 'credit-card',
                'is_published' => true,
                'sort_order' => 11,
            ],
            [
                'title' => 'Workflow Automation',
                'service_category' => 'Workflow Automation',
                'category' => 'Automation',
                'summary' => 'Automate repetitive tasks with triggers, conditions, and actions across your systems.',
                'description' => 'Visual workflow builder (n8n-style or custom) connecting forms, approvals, notifications, API calls, and database updates. Reduces manual handoffs and enforces process consistency.',
                'features' => ['Visual builder', 'Multi-step flows', 'Conditional logic', 'Email/SMS/webhook', 'Audit history'],
                'price_label' => 'From KES 70,000',
                'icon' => 'git-branch',
                'is_published' => true,
                'sort_order' => 12,
            ],
            [
                'title' => 'Digital Transformation Consulting',
                'service_category' => 'Digital Transformation Consulting',
                'category' => 'Consulting',
                'summary' => 'Strategy, architecture, and delivery oversight for organizations modernizing operations.',
                'description' => 'We assess current systems, define target architecture, prioritize initiatives, and oversee delivery. Focus on measurable outcomes: cost reduction, revenue enablement, compliance, and staff capacity.',
                'features' => ['Current state audit', 'Roadmap & cost model', 'Vendor selection', 'Delivery oversight', 'Change management'],
                'price_label' => 'From KES 50,000/day',
                'icon' => 'lightbulb',
                'is_published' => true,
                'sort_order' => 13,
            ],
            [
                'title' => 'Custom Software Development',
                'service_category' => 'Custom Software Development',
                'category' => 'Custom Development',
                'summary' => 'Tailored applications when off-the-shelf does not fit your processes.',
                'description' => 'Full lifecycle: discovery, UX design, architecture, development, testing, deployment, and support. We work in 2-week sprints with demo reviews. Tech stack: Laravel, PostgreSQL/MySQL, Redis, Docker, CI/CD.',
                'features' => ['Agile sprints', 'UX design', 'Automated tests', 'CI/CD pipeline', 'Documentation'],
                'price_label' => 'Project-based',
                'icon' => 'code',
                'is_published' => true,
                'sort_order' => 14,
            ],
            [
                'title' => 'Hosting, Maintenance & Technical Support',
                'service_category' => 'Hosting, Maintenance & Technical Support',
                'category' => 'Support',
                'summary' => 'Managed Laravel hosting on AWS/DigitalOcean with proactive monitoring and rapid incident response.',
                'description' => 'Server provisioning, SSL, backups, monitoring, security patches, and 8×5 support. Includes quarterly dependency updates, performance tuning, and incident response. Regionally based support team.',
                'features' => ['AWS/DO hosting', 'SSL & backups', 'Security patches', '8×5 support', 'Quarterly updates'],
                'price_label' => 'From KES 15,000/mo',
                'icon' => 'server',
                'is_published' => true,
                'sort_order' => 15,
            ],
        ];

        foreach ($services as $index => $service) {
            $service['slug'] = Str::slug($service['title']);
            DigitalSolution::updateOrCreate(
                ['slug' => $service['slug']],
                $service
            );
        }

        // FAQs
        $faqs = [
            [
                'question' => 'What makes DEMO Digital Solutions different from a typical web design agency?',
                'answer' => 'We are a division of Demo NGO, a registered CBO in Harbor City. Our work directly funds youth mentorship, digital labs, and the annual Youth Leadership Summit conference. We build production-grade software — not just brochure sites — with a focus on Kenyan context (M-Pesa, Daraja, offline-first, CBC curriculum).',
                'group' => 'General',
                'sort_order' => 1,
                'is_published' => true,
            ],
            [
                'question' => 'Do you only work with organizations in Harbor City?',
                'answer' => 'No. Our team works remotely with clients across Kenya and East Africa. On-site discovery workshops are available where needed.',
                'group' => 'General',
                'sort_order' => 2,
                'is_published' => true,
            ],
            [
                'question' => 'How do you handle M-Pesa integration?',
                'answer' => 'We use Safaricom Daraja API directly (STK Push, B2C, B2B, Reversal, Transaction Status). We do not rely on third-party aggregators, which means lower fees, full control, and real-time reconciliation. All integrations include idempotency keys, webhook verification, and automated retry logic.',
                'group' => 'Technical',
                'sort_order' => 3,
                'is_published' => true,
            ],
            [
                'question' => 'What is your typical project timeline?',
                'answer' => 'A standard website: 4–6 weeks. A custom web application: 8–16 weeks depending on scope. Mobile apps: 12–20 weeks. We work in 2-week sprints with a demo at the end of each. You approve scope before each sprint begins.',
                'group' => 'Process',
                'sort_order' => 4,
                'is_published' => true,
            ],
            [
                'question' => 'Do you provide ongoing support after launch?',
                'answer' => 'Yes. We offer managed hosting and support plans starting at KES 15,000/month. This includes security patches, dependency updates, uptime monitoring, backups, and 8×5 email/phone support. Emergency support is available as an add-on.',
                'group' => 'Support',
                'sort_order' => 5,
                'is_published' => true,
            ],
            [
                'question' => 'Can you work with our existing team or vendors?',
                'answer' => 'Absolutely. We frequently collaborate with internal IT teams, designers, and other agencies. We provide API documentation, developer onboarding, and can hand off codebases with full documentation and CI/CD pipelines.',
                'group' => 'Process',
                'sort_order' => 6,
                'is_published' => true,
            ],
        ];

        foreach ($faqs as $faq) {
            DigitalSolutionFaq::updateOrCreate(
                ['question' => $faq['question']],
                $faq
            );
        }

        // Industries Served
        $industries = [
            ['name' => 'Non-Profits & NGOs', 'icon' => 'users', 'summary' => 'Case management, donor reporting, grant compliance, and volunteer coordination.', 'sort_order' => 1, 'is_published' => true],
            ['name' => 'Schools & Education', 'icon' => 'graduation-cap', 'summary' => 'Student information, fee collection (M-Pesa), CBC grading, parent portals, NEMIS.', 'sort_order' => 2, 'is_published' => true],
            ['name' => 'SMEs & Startups', 'icon' => 'briefcase', 'summary' => 'ERP, CRM, inventory, POS, e-commerce, and workflow automation.', 'sort_order' => 3, 'is_published' => true],
            ['name' => 'County & Local Government', 'icon' => 'building', 'summary' => 'Citizen service portals, revenue collection, project tracking, and transparency dashboards.', 'sort_order' => 4, 'is_published' => true],
            ['name' => 'Faith-Based Organizations', 'icon' => 'heart', 'summary' => 'Member management, tithe/offering tracking (M-Pesa), event registration, and communication.', 'sort_order' => 5, 'is_published' => true],
            ['name' => 'Healthcare & Clinics', 'icon' => 'stethoscope', 'summary' => 'Patient records, appointment booking, pharmacy inventory, and NHIF claims.', 'sort_order' => 6, 'is_published' => true],
            ['name' => 'Hospitality & Tourism', 'icon' => 'map-pin', 'summary' => 'Booking engines, channel managers, POS, and guest experience apps.', 'sort_order' => 7, 'is_published' => true],
            ['name' => 'Professional Services', 'icon' => 'briefcase', 'summary' => 'Practice management, time tracking, invoicing, and client portals.', 'sort_order' => 8, 'is_published' => true],
        ];

        foreach ($industries as $industry) {
            DigitalSolutionIndustry::updateOrCreate(
                ['name' => $industry['name']],
                $industry
            );
        }

        // Technology Capabilities
        $tech = [
            ['name' => 'Laravel (PHP)', 'icon' => 'code', 'description' => 'Primary backend framework. API resources, queues, broadcasting, Octane-ready.', 'group' => 'Backend', 'sort_order' => 1, 'is_published' => true],
            ['name' => 'MySQL / PostgreSQL', 'icon' => 'database', 'description' => 'Relational data with full-text search, JSON columns, and read replicas.', 'group' => 'Backend', 'sort_order' => 2, 'is_published' => true],
            ['name' => 'Redis', 'icon' => 'database', 'description' => 'Caching, session store, queue driver, and rate limiting.', 'group' => 'Backend', 'sort_order' => 3, 'is_published' => true],
            ['name' => 'Tailwind CSS + Alpine.js', 'icon' => 'layout', 'description' => 'Utility-first styling and lightweight reactivity for fast, accessible UIs.', 'group' => 'Frontend', 'sort_order' => 1, 'is_published' => true],
            ['name' => 'Vue.js / Livewire', 'icon' => 'layout', 'description' => 'Reactive components for complex dashboards and real-time features.', 'group' => 'Frontend', 'sort_order' => 2, 'is_published' => true],
            ['name' => 'Flutter / React Native', 'icon' => 'smartphone', 'description' => 'Cross-platform mobile apps with shared Laravel backend.', 'group' => 'Mobile', 'sort_order' => 1, 'is_published' => true],
            ['name' => 'Safaricom Daraja (M-Pesa)', 'icon' => 'credit-card', 'description' => 'STK Push, B2C, B2B, Reversal, Transaction Status, Callback handling.', 'group' => 'Integrations', 'sort_order' => 1, 'is_published' => true],
            ['name' => 'Paystack / Stripe', 'icon' => 'credit-card', 'description' => 'Card, mobile money, and bank transfers across Africa.', 'group' => 'Integrations', 'sort_order' => 2, 'is_published' => true],
            ['name' => 'Docker & CI/CD', 'icon' => 'server', 'description' => 'Containerized deployments, GitHub Actions pipelines, zero-downtime releases.', 'group' => 'DevOps', 'sort_order' => 1, 'is_published' => true],
            ['name' => 'AWS / DigitalOcean', 'icon' => 'cloud', 'description' => 'Managed hosting with load balancing, RDS, S3, CloudFront, and monitoring.', 'group' => 'DevOps', 'sort_order' => 2, 'is_published' => true],
        ];

        foreach ($tech as $t) {
            DigitalSolutionTech::updateOrCreate(
                ['name' => $t['name']],
                $t
            );
        }

        // Portfolio / Case Studies — FK resolved by slug (never hardcoded ids),
        // outcomes kept to facts already published on the DEMO site.
        $portfolio = [
            [
                'solution_slug' => 'youth-digital-lab',
                'title' => 'Riverside & Northside weekend digital labs',
                'slug' => 'riverside-northside-weekend-digital-labs',
                'client' => 'Demo NGO',
                'location' => 'Harbor City, Kenya',
                'year' => '2026',
                'summary' => 'The attendance and cohort portal behind DEMO\'s mobile digital labs: enrollment, session tracking, project submission, and mentor grading for weekend coding bootcamps in Riverside and Northside.',
                'outcome' => 'Runs every weekend as the operating system of the free youth lab cohorts.',
                'image_url' => '',
                'is_published' => true,
                'is_featured' => false,
                'sort_order' => 2,
            ],
            [
                'solution_slug' => 'demo-media-studio',
                'title' => 'DEMO Media Hub YouTube syndication pipeline',
                'slug' => 'demo-media-youtube-syndication-pipeline',
                'client' => 'Demo NGO',
                'location' => 'Harbor City, Kenya',
                'year' => '2025',
                'summary' => 'Automated YouTube Data API v3 ingestion with local caching: the media hub publishes and archives DEMO TV storytelling without manual uploads, and stays browsable when connectivity drops.',
                'outcome' => 'Powers the public media hub at /media on this site today.',
                'image_url' => '',
                'is_published' => true,
                'is_featured' => false,
                'sort_order' => 3,
            ],
        ];

        foreach ($portfolio as $item) {
            $solutionId = DigitalSolution::where('slug', $item['solution_slug'])->value('id');
            if (!$solutionId) {
                continue;
            }
            unset($item['solution_slug']);
            DigitalPortfolioItem::updateOrCreate(
                ['slug' => $item['slug']],
                $item + ['digital_solution_id' => $solutionId]
            );
        }
    }
}