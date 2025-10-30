<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LOR Templates Configuration
    |--------------------------------------------------------------------------
    |
    | Инструкции для AI ассистентов вместо хранения в БД
    |
    */

    'main_assistant' => [
        'instructions' => "You're a virtual assistant who helps people improve their resumes.

If a user asks you for a question unrelated to your role, respond with something like, \"I can't help you with that.\"

Ask the user to send a job posting and their resume. They can upload this information as files. If the user has submitted both the job posting and resume information, check how well the resume matches the job posting and make suggestions for changes to better align the resume with the job posting. For example, the job posting asks for an HTML and CSS specialist, but the resume says web developer. Recommend changing the resume from web developer to HTML and CSS. This is because the job posting and resume are inconsistent in terms of technologies and keywords. Your job is to make recommendations for improving the resume to meet the requirements of the job posting.

IMPORTANT: Once you have collected ALL the necessary information from the user:
- Full name (NAME SURNAME)
- Email address
- Phone number
- Profile/Description (main strengths, MAX 380 characters)
- Work experience (period, company, position, job description, achievements, metrics)
- Education (period, institution, degree)
- Skills (technical tools, languages with levels)
- Projects (if applicable)

You MUST call the generate_resume_pdf function with all the collected data. Do not ask for additional information if you already have all required fields. After the function executes successfully, inform the user that the PDF resume has been generated and sent to them.",
        'model' => 'gpt-5-mini',
        'temperature' => 1.00,
        'response_format' => 'text',
        'tools' => [
            [
                'type' => 'function',
                'name' => 'generate_resume_pdf',
                'description' => 'Generates a PDF resume from structured data and sends it to the user via Telegram. After executing this function, a PDF file will be automatically sent to the user. You should inform the user that the PDF has been generated and sent to them.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'Full name (NAME SURNAME)',
                        ],
                        'email' => [
                            'type' => 'string',
                            'description' => 'Email address',
                        ],
                        'phone' => [
                            'type' => 'string',
                            'description' => 'Phone number',
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Profile/Description - main strengths (MAX 380 characters)',
                        ],
                        'experience' => [
                            'type' => 'array',
                            'description' => 'Array of work experience items',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'period' => ['type' => 'string', 'description' => 'MONTH/YEAR – MONTH/YEAR or PRESENT'],
                                    'company' => ['type' => 'string', 'description' => 'Company name'],
                                    'position' => ['type' => 'string', 'description' => 'Position name'],
                                    'job_description' => ['type' => 'string', 'description' => 'Job description'],
                                    'achievements' => ['type' => 'string', 'description' => 'Achievements'],
                                    'metrics' => ['type' => 'string', 'description' => 'Numeric proof of success'],
                                ],
                                'required' => ['period', 'company', 'position'],
                            ],
                        ],
                        'education' => [
                            'type' => 'array',
                            'description' => 'Array of education items',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'period' => ['type' => 'string', 'description' => 'YEAR - YEAR'],
                                    'institution' => ['type' => 'string', 'description' => 'Name of the institution'],
                                    'degree' => ['type' => 'string', 'description' => 'BA / MA, major (specialization)'],
                                ],
                                'required' => ['period', 'institution', 'degree'],
                            ],
                        ],
                        'skills' => [
                            'type' => 'object',
                            'properties' => [
                                'technical' => [
                                    'type' => 'string',
                                    'description' => 'Tools & Technical skills',
                                ],
                                'languages' => [
                                    'type' => 'array',
                                    'description' => 'Languages with levels',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'language' => ['type' => 'string'],
                                            'level' => ['type' => 'string', 'description' => 'Native / Professional / Elementary'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'projects' => [
                            'type' => 'array',
                            'description' => 'Projects (if applicable)',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                    'required' => ['name', 'email', 'phone', 'description', 'experience', 'education', 'skills'],
                ],
            ],
        ],
    ],

];

