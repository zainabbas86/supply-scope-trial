import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';

import { appUrl } from '@/lib/url';

/*
 * The public site root.
 *
 * Standalone, not wrapped in AppLayout: that layout is the authenticated app
 * shell, with a sign-out button and flash regions that mean nothing here.
 *
 * The CV content is data in this file rather than props from PHP. It is static
 * prose with no server-side source of truth, so routing it through a controller
 * would add a serialisation layer and a second place to edit. Only the contact
 * links come from config, because those are what might change without a
 * frontend rebuild.
 */

type Contact = {
    email: string;
    github: string;
    linkedin: string;
};

type Role = {
    title: string;
    company: string;
    location: string;
    period: string;
    summary: string;
    highlights: string[];
    stack: string[];
};

const SKILLS: Array<{ group: string; items: string }> = [
    {
        group: 'Languages & frameworks',
        items: 'TypeScript, JavaScript, Node.js, NestJS, React, Next.js, PHP, Laravel, Symfony, Yii, Java, ASP.NET, C#, C++, WordPress, Magento',
    },
    {
        group: 'Cloud & DevOps',
        items: 'AWS Lambda, ECS, EKS, EC2, API Gateway, S3, DynamoDB, SQS, SNS, CloudWatch, IAM, Docker, Podman, Terraform, Jenkins, GitHub Actions, Azure',
    },
    {
        group: 'Data & storage',
        items: 'PostgreSQL, MySQL, SQL Server, DynamoDB, Redis, Elasticsearch / OpenSearch',
    },
    {
        group: 'AI & automation',
        items: 'AWS Bedrock, AWS Comprehend, agentic AI, RAG, AI search, prompt engineering, MCP concepts, Claude, Claude Code, Kiro, Copilot',
    },
    {
        group: 'Architecture',
        items: 'Microservices, event-driven architecture, REST APIs, GraphQL, OAuth, SSO, serverless',
    },
];

const ROLES: Role[] = [
    {
        title: 'Senior Software Engineer',
        company: 'Domain Australia',
        location: 'Pyrmont, NSW',
        period: 'Jul 2026 — Present',
        summary:
            'Breaking down the Real Time Agent platform — a Python/Django monolith on PostgreSQL — into NestJS/TypeScript microservices.',
        highlights: [
            'Decomposing a Django monolith into NestJS/TypeScript services',
            'AWS EKS, SQS, SNS and IAM policy design; Podman for local development',
            'CI/CD across GitHub Actions, Terraform and Jenkins',
            'Planning, shipping and reviewing with Claude Code and Copilot day to day',
        ],
        stack: ['NestJS', 'TypeScript', 'Django', 'PostgreSQL', 'EKS', 'Terraform'],
    },
    {
        title: 'Senior Full Stack Developer',
        company: 'LivePro',
        location: 'Sydney, NSW',
        period: 'Oct 2023 — May 2026',
        summary:
            'SaaS knowledge management platform. Requirements through to deployment, with a focus on serverless AI services layered onto an established PHP product.',
        highlights: [
            'Built Redact, an AI-powered PII redaction microservice on AWS Comprehend, Lambda, API Gateway and SAM',
            'Shipped AI content assistance (Summarise, Rewrite, Make Professional) into a React/TinyMCE editor using AWS Bedrock',
            'Engineered an AI search service turning natural language into multiple searchable questions against a RAG knowledge base',
            'Migrated an agentic AI prototype from Express.js to NestJS, resolving SQLite vector, Docker build and ECS deployment issues',
            'Maintained and refactored legacy PHP Yii and ExtJS alongside modern React TypeScript',
        ],
        stack: ['PHP Yii', 'React', 'NestJS', 'AWS Bedrock', 'Lambda', 'DynamoDB'],
    },
    {
        title: 'Project Lead Developer',
        company: 'Hello Molly',
        location: 'Alexandria, Sydney NSW',
        period: 'Apr 2023 — Oct 2023',
        summary:
            'Led scoping, architecture and prototyping of a procurement system intended as the core module of a future ERP platform.',
        highlights: [
            'Replaced an Excel-based procurement process with a Laravel and React TypeScript application',
            'Integrated Shopify and an existing Symfony middleware to consolidate product, sales and inventory data',
            'Built secure REST APIs with JWT authentication between Laravel and React',
            'Delivered an executive-facing Next.js prototype with dashboards and end-to-end workflows',
        ],
        stack: ['Laravel', 'React', 'Next.js', 'TypeScript', 'Shopify', 'Symfony'],
    },
    {
        title: 'Tech Lead',
        company: 'Media Merchants',
        location: 'Pyrmont, Sydney NSW',
        period: 'Jun 2021 — Apr 2023',
        summary:
            'Project-management-focused technical lead across multiple client platforms, owning architecture, costing, delivery and developer mentoring.',
        highlights: [
            'Chose stacks and owned solution architecture for clients including Good Price Pharmacy, Betta, National Product Review and NARTA',
            'Ran Agile delivery — SCRUM, sprints and daily standups — across all projects',
            'Recovered a broken Magento 2 site after a Puppet master reset wiped cloud config overrides, then migrated it to a new provider',
            'Dockerised Magento development environments including RabbitMQ, Redis, MySQL and PHP',
        ],
        stack: ['WordPress', 'Magento 2', 'React', 'Docker', 'ASP.NET', 'Nginx'],
    },
    {
        title: 'PHP Developer',
        company: '4WD Supacentre',
        location: 'Silverwater, Sydney NSW',
        period: 'Aug 2019 — Jun 2021',
        summary:
            'Full stack development across a Symfony backend, a Magento storefront and a new React PWA.',
        highlights: [
            'Upgraded a legacy Symfony system and assisted the Magento 1 to Magento 2 migration',
            'Set up PHPUnit testing for Symfony projects',
            'Wrote Python and Node Lambdas, including a print service for label printers',
            'Optimised database queries and worked across EC2, SQS and CloudFront',
        ],
        stack: ['Symfony', 'Magento 2', 'React', 'AngularJS', 'AWS', 'Python'],
    },
    {
        title: 'PHP Developer',
        company: 'News Corp Australia',
        location: 'Surry Hills, Sydney NSW',
        period: 'Apr 2018 — Jul 2019',
        summary:
            'Full stack developer in Food Corp, covering taste.com.au, delicious.com.au and bestrecipes.com.au.',
        highlights: [
            'Implemented AMP pages for delicious.com.au',
            'Helped migrate taste.com.au onto a CMS shared across all food brands',
            'Maintained applications on AWS and provided on-call support',
            'Worked on file and image processing Lambdas written in Python and GoLang',
        ],
        stack: ['PHP', 'Symfony', 'Vue', 'React', 'WordPress', 'AWS', 'GoLang'],
    },
    {
        title: 'PHP Developer',
        company: 'Elmo',
        location: 'Bondi Junction, Sydney NSW',
        period: 'Apr 2017 — May 2018',
        summary:
            'Talent management software used by organisations including NSW Government and the ATO.',
        highlights: [
            'Built and maintained the Rewards and Recommendations, Payroll, Reports and Appraisals modules',
            'Worked across Symfony 2, Twig and AngularJS',
        ],
        stack: ['PHP', 'Symfony 2', 'AngularJS', 'Twig'],
    },
    {
        title: 'Web Developer (Contract)',
        company: 'CBS Interactive',
        location: 'Sydney CBD, NSW',
        period: 'Apr 2016 — Mar 2017',
        summary:
            'Worked with the CBS Sports team on cbssports.com, in an Agile environment spanning multiple international teams.',
        highlights: [
            'NBA and College Basketball Gametracker',
            'NFL Gametracker',
            'Player stats, player rankings and fantasy rankings across all sports',
        ],
        stack: ['PHP', 'Symfony 2', 'Backbone', 'Marionette', 'REST'],
    },
    {
        title: 'Web Developer',
        company: '4WD Supacentre / Express Media Group',
        location: 'Silverwater, NSW',
        period: 'Oct 2014 — Mar 2016',
        summary:
            'Magento storefronts plus internal Symfony applications for warehouse consignment, claims and reporting.',
        highlights: [
            'Built and maintained warehouse consignment and claims/refund management systems',
            'Wrote PHPUnit, Karma/Jasmine and Selenium end-to-end tests',
            'Built dynamic reporting with slick.js and charts.js',
        ],
        stack: ['Magento', 'Symfony 2', 'AngularJS', 'Selenium', 'PHPUnit'],
    },
];

const OVERSEAS: Role[] = [
    {
        title: 'Computer Programmer',
        company: 'PMDC — Project Management and Development Co.',
        location: 'Jeddah, Saudi Arabia',
        period: 'May 2009 — Sep 2014',
        summary:
            'Enterprise internal systems across finance, inventory, HR and telecommunications, from analysis through to implementation and support.',
        highlights: [
            'Developed and implemented the General Ledger system',
            'Built the Software Development and Support system, and the Notification system',
            'Delivered Material Acquisition and Item Management for Stocks and Inventory',
            'Trained junior programmers and led project delivery',
        ],
        stack: ['PHP', 'MySQL', 'JavaScript', 'Visual FoxPro'],
    },
    {
        title: 'Software Engineer',
        company: 'DCC — Data Communication and Control',
        location: 'Karachi, Pakistan',
        period: 'Aug 2008 — Apr 2009',
        summary:
            'Defence training simulators — real-time systems with demanding correctness requirements.',
        highlights: [
            'Action Speed Tactical Trainer (ASTT) wake simulator module',
            'Submarine training simulator for Agosta class submarines',
            'GUI work for a CNC machine controller and a tank gun firing simulator',
        ],
        stack: ['C++', 'C# .NET', 'WPF / XAML', 'SQL Server', 'HLA'],
    },
];

/**
 * The header photo, with initials as a fallback.
 *
 * A missing file renders as a broken-image icon, which looks worse on a
 * portfolio than having no photo at all. onError is not defensive padding
 * here: the image is a deploy-time asset that can plausibly be absent from a
 * fresh checkout, and this page is the first thing a recruiter sees.
 */
function Avatar() {
    const [failed, setFailed] = useState(false);

    if (failed) {
        return (
            <div
                aria-hidden="true"
                className="flex h-28 w-28 shrink-0 items-center justify-center rounded-full border border-neutral-200 bg-white text-2xl font-semibold text-neutral-400"
            >
                ZA
            </div>
        );
    }

    return (
        <img
            src="/images/zain.jpg"
            alt="Zain Abbas"
            width={112}
            height={112}
            onError={() => setFailed(true)}
            className="h-28 w-28 shrink-0 rounded-full border border-neutral-200 bg-white object-cover object-top"
        />
    );
}

function Section({
    id,
    title,
    children,
}: {
    id: string;
    title: string;
    children: ReactNode;
}) {
    return (
        <section id={id} className="border-t border-neutral-200 py-12">
            <h2 className="mb-6 text-xs font-semibold uppercase tracking-widest text-neutral-500">
                {title}
            </h2>
            {children}
        </section>
    );
}

function RoleCard({ role }: { role: Role }) {
    return (
        <article className="relative pb-10 pl-6 last:pb-0">
            {/* Timeline marker and rail. Decorative, so it is drawn rather than
                marked up — a screen reader gets the heading order instead. */}
            <span
                aria-hidden="true"
                className="absolute left-0 top-2 h-2 w-2 rounded-full bg-teal-600"
            />
            <span
                aria-hidden="true"
                className="absolute bottom-0 left-[3.5px] top-5 w-px bg-neutral-200 last:hidden"
            />

            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h3 className="text-base font-semibold text-neutral-900">
                    {role.title}
                    <span className="font-normal text-neutral-500"> · {role.company}</span>
                </h3>
                <p className="text-sm tabular-nums text-neutral-500">{role.period}</p>
            </div>

            <p className="mt-0.5 text-sm text-neutral-500">{role.location}</p>
            <p className="mt-3 text-sm leading-relaxed text-neutral-700">{role.summary}</p>

            <ul className="mt-3 space-y-1.5">
                {role.highlights.map((highlight) => (
                    <li
                        key={highlight}
                        className="relative pl-4 text-sm leading-relaxed text-neutral-600"
                    >
                        <span
                            aria-hidden="true"
                            className="absolute left-0 text-neutral-300"
                        >
                            –
                        </span>
                        {highlight}
                    </li>
                ))}
            </ul>

            <ul className="mt-4 flex flex-wrap gap-1.5">
                {role.stack.map((tech) => (
                    <li
                        key={tech}
                        className="rounded-md bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600"
                    >
                        {tech}
                    </li>
                ))}
            </ul>
        </article>
    );
}

export default function Portfolio({ contact }: { contact: Contact }) {
    return (
        <>
            <Head title="Zain Abbas — Software Developer & Technical Lead" />

            <div className="min-h-screen bg-neutral-50 text-neutral-900">
                <div className="mx-auto max-w-3xl px-4">
                    <header className="flex flex-col gap-6 py-14 sm:flex-row sm:items-center">
                        <Avatar />

                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">Zain Abbas</h1>
                            <p className="mt-1 text-neutral-600">
                                Software Developer, Technical Lead, AI Developer &amp; Consultant
                            </p>
                            <p className="mt-1 text-sm text-neutral-500">
                                Sydney, Australia · 17+ years building for the web
                            </p>

                            <ul className="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-sm">
                                <li>
                                    <a
                                        className="text-teal-700 underline-offset-4 hover:underline"
                                        href={`mailto:${contact.email}`}
                                    >
                                        {contact.email}
                                    </a>
                                </li>
                                <li>
                                    <a
                                        className="text-teal-700 underline-offset-4 hover:underline"
                                        href={contact.github}
                                        target="_blank"
                                        rel="noreferrer noopener"
                                    >
                                        GitHub
                                    </a>
                                </li>
                                <li>
                                    <a
                                        className="text-teal-700 underline-offset-4 hover:underline"
                                        href={contact.linkedin}
                                        target="_blank"
                                        rel="noreferrer noopener"
                                    >
                                        LinkedIn
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </header>

                    {/* The reason this page exists. */}
                    <Link
                        href={appUrl('/login')}
                        className="group block rounded-lg border border-teal-200 bg-white p-5 transition hover:border-teal-400 hover:shadow-sm"
                    >
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-widest text-teal-700">
                                    Live project
                                </p>
                                <h2 className="mt-1 text-lg font-semibold">
                                    Supply Scope Trial App
                                </h2>
                                <p className="mt-2 text-sm leading-relaxed text-neutral-600">
                                    A label extraction agent. Upload a product label or spec sheet
                                    and a queued worker runs it through a vision model to pull out
                                    structured data — product name, brand, ingredients, allergens
                                    and net weight. Laravel, Inertia, React, PostgreSQL and Redis,
                                    with extraction running in its own container.
                                </p>
                            </div>

                            <span
                                aria-hidden="true"
                                className="mt-1 text-xl text-teal-600 transition group-hover:translate-x-0.5"
                            >
                                &rarr;
                            </span>
                        </div>

                        <p className="mt-4 text-sm font-medium text-teal-700">Open the app &rarr;</p>
                    </Link>

                    <Section id="about" title="Summary">
                        <p className="text-sm leading-relaxed text-neutral-700">
                            Senior full stack developer and technical lead with extensive
                            experience delivering cloud-native, serverless and enterprise
                            applications across AWS, PHP, Node.js, React, TypeScript and AI-powered
                            platforms. Experienced leading teams, designing scalable architecture,
                            building APIs and integrations, and delivering AI, automation and
                            search solutions using modern engineering practices.
                        </p>
                    </Section>

                    <Section id="skills" title="Technical skills">
                        <dl className="grid gap-5 sm:grid-cols-2">
                            {SKILLS.map((skill) => (
                                <div key={skill.group}>
                                    <dt className="text-sm font-semibold text-neutral-900">
                                        {skill.group}
                                    </dt>
                                    <dd className="mt-1 text-sm leading-relaxed text-neutral-600">
                                        {skill.items}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </Section>

                    <Section id="experience" title="Experience — Australia">
                        {ROLES.map((role) => (
                            <RoleCard key={`${role.company}-${role.period}`} role={role} />
                        ))}
                    </Section>

                    <Section id="experience-overseas" title="Experience — overseas">
                        {OVERSEAS.map((role) => (
                            <RoleCard key={`${role.company}-${role.period}`} role={role} />
                        ))}
                    </Section>

                    <Section id="education" title="Education">
                        <div className="space-y-5">
                            <div>
                                <h3 className="text-sm font-semibold text-neutral-900">
                                    Graduate Certificate in Data Analytics
                                </h3>
                                <p className="mt-0.5 text-sm text-neutral-600">
                                    Melbourne Institute of Technology, Sydney campus · 2021
                                </p>
                            </div>
                            <div>
                                <h3 className="text-sm font-semibold text-neutral-900">
                                    BSc in Computer Science
                                </h3>
                                <p className="mt-0.5 text-sm text-neutral-600">
                                    National University of Computer and Emerging Sciences
                                    (NUCES-FAST), Karachi · 2004 — 2008
                                </p>
                            </div>
                        </div>
                    </Section>

                    <footer className="border-t border-neutral-200 py-8 text-sm text-neutral-500">
                        <p>
                            Zain Abbas ·{' '}
                            <a
                                className="text-teal-700 underline-offset-4 hover:underline"
                                href={`mailto:${contact.email}`}
                            >
                                {contact.email}
                            </a>
                        </p>
                    </footer>
                </div>
            </div>
        </>
    );
}
