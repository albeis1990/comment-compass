<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

send_security_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Only POST requests are supported.'], 405);
}

$payload = read_json_body();
$passcode = app_env('APP_PASSCODE');

if ($passcode !== '') {
    $submittedPasscode = (string)($payload['passcode'] ?? '');
    if (!hash_equals($passcode, $submittedPasscode)) {
        json_response(['error' => 'The access code is incorrect.'], 401);
    }
}

$validationError = validate_teacher_payload($payload);
if ($validationError !== null) {
    json_response(['error' => $validationError], 422);
}

$apiKey = app_env('OPENAI_API_KEY');
if ($apiKey === '') {
    json_response(['error' => 'OPENAI_API_KEY is not configured on the server.'], 500);
}

$model = app_env('OPENAI_MODEL', 'gpt-5.5');

try {
    $request = report_comment_request($model, $payload);
    $openaiResponse = openai_responses_request($apiKey, $request);
    $result = decode_report_result($openaiResponse);

    $audit = local_comment_audit((string)$result['comment'], $payload);

    if (!$audit['sentence_range']['pass']) {
        $rewriteRequest = report_comment_request($model, $payload, (string)$result['comment'], sentence_count((string)$result['comment']));
        $rewriteResponse = openai_responses_request($apiKey, $rewriteRequest);
        $rewriteResult = decode_report_result($rewriteResponse);
        $rewriteAudit = local_comment_audit((string)$rewriteResult['comment'], $payload);

        if ($rewriteAudit['sentence_range']['pass'] || sentence_count((string)$rewriteResult['comment']) > sentence_count((string)$result['comment'])) {
            $openaiResponse = $rewriteResponse;
            $result = $rewriteResult;
            $audit = $rewriteAudit;
        }
    }

    $result['local_audit'] = $audit;

    json_response([
        'model' => (string)($openaiResponse['model'] ?? $model),
        'result' => $result,
    ]);
} catch (Throwable $error) {
    json_response(['error' => $error->getMessage()], 502);
}

function report_comment_request(string $model, array $payload, ?string $previousComment = null, ?int $previousSentenceCount = null): array
{
    $target = sentence_target($payload['sentenceTarget'] ?? 6);
    $teacherEvidence = json_encode(sanitized_teacher_payload($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($teacherEvidence === false) {
        throw new RuntimeException('The teacher evidence could not be encoded.');
    }

    $userContent = "Teacher evidence:\n{$teacherEvidence}\n\nLength requirement: Write exactly {$target} sentences. This is a hard requirement, not a suggestion. Count the final comment sentences before returning JSON.";

    if ($previousComment !== null && $previousSentenceCount !== null) {
        $userContent .= "\n\nThe previous draft had {$previousSentenceCount} sentences, so it did not meet the required {$target}-sentence length. Rewrite it to exactly {$target} sentences while preserving the same teacher evidence and report-comment rules.\n\nPrevious draft:\n{$previousComment}";
    }

    return [
        'model' => $model,
        'input' => [
            [
                'role' => 'system',
                'content' => report_comment_system_prompt(),
            ],
            [
                'role' => 'user',
                'content' => $userContent,
            ],
        ],
        'store' => false,
        'max_output_tokens' => 6000,
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'report_comment_output',
                'strict' => true,
                'schema' => report_comment_schema(),
            ],
        ],
    ];
}

function decode_report_result(array $openaiResponse): array
{
    $outputText = extract_output_text($openaiResponse);
    $result = json_decode($outputText, true);

    if (!is_array($result) || !isset($result['comment'])) {
        json_response(['error' => 'The model response could not be read. Please try again.'], 502);
    }

    return $result;
}

function validate_teacher_payload(array $payload): ?string
{
    $requiredText = [
        'grade' => 'Grade',
        'strengthEvidence' => 'strongest learning praise',
        'learnerProfileEvidence' => 'learner profile evidence',
        'atlEvidence' => 'approaches to learning evidence',
        'goalOne' => 'most important goal',
    ];

    if (trim((string)($payload['chineseName'] ?? '')) === '' && trim((string)($payload['englishName'] ?? '')) === '') {
        return 'Add a Chinese name or English given name.';
    }

    foreach ($requiredText as $key => $label) {
        if (trim((string)($payload[$key] ?? '')) === '') {
            return "Add {$label}.";
        }
    }

    $grade = (string)($payload['grade'] ?? '');
    if (!in_array($grade, ['Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5'], true)) {
        return 'Choose Grade 1, Grade 2, Grade 3, Grade 4 or Grade 5.';
    }

    if (empty($payload['learnerProfile']) || !is_array($payload['learnerProfile'])) {
        return 'Choose at least one learner profile attribute.';
    }

    if (empty($payload['atlSkills']) || !is_array($payload['atlSkills'])) {
        return 'Choose at least one approach to learning.';
    }

    return null;
}

function sanitized_teacher_payload(array $payload): array
{
    unset($payload['passcode']);

    return [
        'student' => [
            'chineseName' => clean_text($payload['chineseName'] ?? ''),
            'englishName' => clean_text($payload['englishName'] ?? ''),
            'grade' => clean_text($payload['grade'] ?? ''),
            'period' => clean_text($payload['period'] ?? ''),
            'pronouns' => clean_text($payload['pronouns'] ?? ''),
        ],
        'evidence' => [
            'strongestLearningPraise' => clean_text($payload['strengthEvidence'] ?? ''),
            'English' => clean_text($payload['englishEvidence'] ?? ''),
            'Chinese' => clean_text($payload['chineseEvidence'] ?? ''),
            'mathematics' => clean_text($payload['mathEvidence'] ?? ''),
            'Unit of Inquiry' => clean_text($payload['uoiEvidence'] ?? ''),
            'Philosophy for Children' => clean_text($payload['p4cEvidence'] ?? ''),
            'eventOrWiderSchool' => clean_text($payload['eventEvidence'] ?? ''),
        ],
        'ib' => [
            'learnerProfile' => array_slice(array_map('clean_text', (array)($payload['learnerProfile'] ?? [])), 0, 2),
            'learnerProfileEvidence' => clean_text($payload['learnerProfileEvidence'] ?? ''),
            'approachesToLearning' => array_slice(array_map('clean_text', (array)($payload['atlSkills'] ?? [])), 0, 2),
            'approachesEvidence' => clean_text($payload['atlEvidence'] ?? ''),
        ],
        'nextSteps' => [
            'goalOne' => clean_text($payload['goalOne'] ?? ''),
            'goalTwo' => clean_text($payload['goalTwo'] ?? ''),
            'support' => clean_text($payload['support'] ?? ''),
        ],
        'style' => [
            'sentenceTarget' => sentence_target($payload['sentenceTarget'] ?? 6),
            'finalEncouragement' => clean_text($payload['encouragement'] ?? ''),
        ],
    ];
}

function clean_text(mixed $value): string
{
    $text = trim((string)$value);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return function_exists('mb_substr') ? mb_substr($text, 0, 900) : substr($text, 0, 900);
}

function sentence_target(mixed $value): int
{
    $target = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => [
            'default' => 6,
            'min_range' => 5,
            'max_range' => 30,
        ],
    ]);

    return is_int($target) ? $target : 6;
}

function report_comment_system_prompt(): string
{
    return <<<'PROMPT'
You write formal IB primary school end-of-semester and end-of-year report comments for Grade 1 to Grade 5 students.

Follow these non-negotiable rules:
- Use a formal report-comment genre with passive voice where it sounds natural. Do not use first-person personal pronouns such as I, me, my, we, us or our.
- Keep each sentence concise, effective, positive and cohesive.
- Positive achievement comments must outweigh improvement comments.
- Include specific evidence from the teacher input. Do not invent events, subjects, behaviour, goals or achievements.
- Include one or two areas for improvement only. Each improvement must be written as a positive, specific next goal.
- End with one short praise or encouragement sentence.
- If a Chinese name is supplied, the first sentence must begin with "ChineseName (EnglishName)" when an English name is supplied. If the student is named again, use the Chinese name consistently, not the English name.
- If no Chinese name is supplied, use the English given name consistently.
- Do not use abbreviations. Write Unit of Inquiry, Philosophy for Children and Extra Curricular Activities in full.
- Capitalize English, Chinese, Philosophy for Children, Unit of Inquiry, Grade and named events such as Sports Day. Sports Day has no apostrophe.
- Write maths or mathematics in lowercase.
- Avoid unnecessary information, redundant words, lists of curriculum coverage and comments that only say a task was completed.
- Use clear parent-friendly language. Avoid specialist terms such as higher order thinking, metacognition, transdisciplinary, summative assessment or conceptual understanding.
- Include learner profile attributes and approaches to learning only when connected to evidence, and express them in parent-friendly language.
- Choose the most important evidence and goals. Do not crowd the report.
- The target length is the teacher's requested number of sentences, from 5 to 30. This is a hard requirement. The final comment must contain exactly that number of sentences.

Approved comment style to follow:
- Begin with a polished overall learner-profile sentence, such as a principled, caring, knowledgeable, courageous or inquiring attitude, connected to specific evidence.
- Use a clear subject-by-subject flow when evidence is supplied: English, Chinese, maths, Unit of Inquiry, Philosophy for Children, then wider school events or community contributions.
- Name each subject explicitly when giving evidence or a goal for that subject. For example, write "To continue progressing in English..." when the goal is about English.
- Include important missing subjects when the teacher has provided evidence for them; do not omit English, maths or Unit of Inquiry evidence if it is present.
- Avoid very long combined sentences. If two goals are included, put them in separate sentences and start the second with "Another goal...".
- When school or parent support is supplied, connect it directly to the next goal instead of adding a separate generic sentence.
- Use wider-school evidence naturally, such as participation in a Grade musical or Sports Day, after the subject evidence and before the goals.
- Do not include reviewer notes, bracketed questions or uncertainty markers in the final comment.

Return only JSON that matches the supplied schema.
PROMPT;
}

function report_comment_schema(): array
{
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['comment', 'checklist', 'revision_notes'],
        'properties' => [
            'comment' => [
                'type' => 'string',
                'description' => 'The finished report comment.',
            ],
            'checklist' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => [
                    'formal_genre',
                    'positive_balance',
                    'evidence_used',
                    'learner_profile_used',
                    'approaches_to_learning_used',
                    'improvement_goals_count',
                    'name_rule_applied',
                    'capitalization_reviewed',
                    'parent_friendly',
                    'final_encouragement',
                ],
                'properties' => [
                    'formal_genre' => ['type' => 'boolean'],
                    'positive_balance' => ['type' => 'boolean'],
                    'evidence_used' => ['type' => 'boolean'],
                    'learner_profile_used' => ['type' => 'boolean'],
                    'approaches_to_learning_used' => ['type' => 'boolean'],
                    'improvement_goals_count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 2],
                    'name_rule_applied' => ['type' => 'boolean'],
                    'capitalization_reviewed' => ['type' => 'boolean'],
                    'parent_friendly' => ['type' => 'boolean'],
                    'final_encouragement' => ['type' => 'boolean'],
                ],
            ],
            'revision_notes' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'maxItems' => 4,
            ],
        ],
    ];
}

function local_comment_audit(string $comment, array $payload): array
{
    $studentName = trim((string)($payload['chineseName'] ?? '')) ?: trim((string)($payload['englishName'] ?? ''));
    $hasForbiddenPronoun = preg_match('/\b(I|me|my|mine|we|us|our|ours)\b/u', $comment) === 1;
    $hasAbbreviation = preg_match('/\b(UOI|P4C|ECA)\b/u', $comment) === 1;
    $startsWithExpectedName = $studentName !== '' && str_starts_with($comment, $studentName);
    $sentenceCount = sentence_count($comment);
    $target = sentence_target($payload['sentenceTarget'] ?? 6);

    return [
        'no_first_person' => [
            'label' => 'No first-person pronouns found',
            'pass' => !$hasForbiddenPronoun,
        ],
        'no_banned_abbreviations' => [
            'label' => 'No banned abbreviations found',
            'pass' => !$hasAbbreviation,
        ],
        'starts_with_expected_name' => [
            'label' => 'Comment starts with the expected student name',
            'pass' => $startsWithExpectedName,
        ],
        'sentence_range' => [
            'label' => "Sentence count: {$sentenceCount} of target {$target}",
            'pass' => $sentenceCount === $target,
        ],
    ];
}

function sentence_count(string $comment): int
{
    return count(array_filter(preg_split('/(?<=[.!?])\s+/u', trim($comment)) ?: []));
}
