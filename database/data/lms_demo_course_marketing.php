<?php

/**
 * LMS course marketing tabs payload (stored in courses.marketing_json).
 */

return [
    'course-ce-review' => [
        /** Demo CDN URL persisted in DB (courses.marketing_json) for course-detail hero banner. */
        'bannerImageUrl' => 'https://picsum.photos/seed/eerc-banner-course-ce-review/1200/675',
        'paragraphs' => [
            'This structured review track is designed for engineering learners who need a cleaner way to study major subjects, revisit difficult formulas, and move through guided modules in a logical sequence. Lessons combine recorded walkthroughs, technical references, and review checkpoints in one course experience.',
            'Each module is arranged to support both theory and application. Learners can move from concept refreshers into practice drills, follow instructor guidance, and prepare for mock-board style assessments without losing context between topics.',
        ],
        'faq' => [
            ['question' => 'Is this course suitable for first-time board review learners?', 'answer' => 'Yes. The course starts with concept refreshers, then gradually builds into guided drills and structured practice sessions.'],
            ['question' => 'Does the course include downloadable references?', 'answer' => 'Yes. Modules can include PDFs, e-books, and reference materials alongside the main lesson flow.'],
            ['question' => 'Can this be used by review centers for group cohorts?', 'answer' => 'Yes. The layout supports individual study as well as organized cohort delivery with instructor oversight.'],
        ],
        'notices' => [
            'Streaming is enabled for core lecture content to keep review materials secure.',
            'Progress is tracked per module so instructors can quickly identify weak areas.',
            'Mock-board coaching sessions can be layered on top of this core review sequence.',
        ],
        'noticeHeading' => 'CE Board Review — announcements',
    ],
    'course-plumbing-mastery' => [
        'bannerImageUrl' => 'https://picsum.photos/seed/eerc-banner-course-plumbing-mastery/1200/675',
        'paragraphs' => [
            'This course is built for plumbing licensure preparation with a practical balance of technical explanation, code review, and solution walkthroughs. Lessons are organized to help learners move from system concepts into field-relevant applications.',
            'Review-center teams can use it to deliver structured plumbing modules, timed practice, and final coaching sessions while keeping learner progress and curriculum visibility in one place.',
        ],
        'faq' => [
            ['question' => 'Does this course focus on code-heavy topics?', 'answer' => 'Yes. Code interpretation and applied problem-solving are central parts of the program content.'],
            ['question' => 'Are practice scenarios included?', 'answer' => 'Yes. The course includes guided drills, module-based reviews, and final coaching content.'],
            ['question' => 'Can instructors use this for live sessions too?', 'answer' => 'Yes. The structure works well for both recorded delivery and live review-center sessions.'],
        ],
        'notices' => [
            'Code review modules are arranged to support both self-paced and guided coaching.',
            'Technical references can be attached per lesson to improve exam readiness.',
            'Learner progress highlights which areas need remediation before mock exams.',
        ],
        'noticeHeading' => 'Master Plumbing — program notes',
    ],
    'course-materials-intensive' => [
        'bannerImageUrl' => 'https://picsum.photos/seed/eerc-banner-course-materials-intensive/1200/675',
        'paragraphs' => [
            'This program is designed for learners who need a more organized way to study materials characterization, thermal processes, and failure analysis. The course combines conceptual refreshers with review checkpoints so technical topics stay manageable.',
            'It is especially useful for programs that want structured digital delivery of advanced lessons, references, and coaching notes across a longer review timeline.',
        ],
        'faq' => [
            ['question' => 'Is this course best for advanced learners?', 'answer' => 'It is designed for intermediate to advanced learners, but the guided sequence still helps structure core refreshers.'],
            ['question' => 'What topics are emphasized most?', 'answer' => 'Metallurgy, thermodynamics, material behavior, heat treatment, and failure analysis are all emphasized.'],
            ['question' => 'Can learners revisit references after the lesson?', 'answer' => 'Yes. Modules can include PDFs, e-books, and structured summaries for later study.'],
        ],
        'notices' => [
            'Advanced modules are arranged to keep high-density technical topics easier to revisit.',
            'Reference materials can be paired with each module to support independent review.',
            'The course flow is ideal for longer intensive programs and exam-season schedules.',
        ],
        'noticeHeading' => 'Materials Engineering — program notes',
    ],
    'course-how-to-design-components' => [
        'bannerImageUrl' => 'https://picsum.photos/seed/eerc-banner-course-design-components/1200/675',
        'paragraphs' => [
            'Interface design fails quietly: spacing drifts, type scales collide, and “almost right” grids create cognitive drag. This course anchors you in repeatable composition habits—establishing grids, aligning modules, and reinforcing affordances so instructional content reads as calmly as intended.',
            'You\'ll move through short theory bursts and applied layout exercises modeled on LMS patterns: hero regions, stacked lessons, FAQs, notices, and review surfaces.',
        ],
        'faq' => [
            ['question' => 'Do we cover design systems?', 'answer' => 'You\'ll practice modular regions and repeatable tokens—the same ingredients design systems formalize.'],
            ['question' => 'Are Figma files required?', 'answer' => 'No. Exercises are layout principles; you can sketch in any tool or in-browser.'],
            ['question' => 'Is this only for web?', 'answer' => 'Patterns apply to web-first LMS UIs; responsive thinking still helps native layouts.'],
        ],
        'notices' => [
            'Preview builds use sample Environmental Sciences metadata to mirror catalog cards.',
            'Completion state in the sidebar is a mock for instructor preview only.',
            'Swap copy in Settings to align with your production program description.',
        ],
        'noticeHeading' => 'Course announcements',
    ],
];
