<?php

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\EvaluationResponse;
use App\Entity\LoadslipVerification;
use App\Repository\AcademicYearRepository;
use App\Repository\CurriculumRepository;
use App\Repository\DepartmentRepository;
use App\Repository\EvaluationPeriodRepository;
use App\Repository\EvaluationResponseRepository;
use App\Repository\FacultySubjectLoadRepository;
use App\Repository\LoadslipVerificationRepository;
use App\Repository\UserRepository;
use App\Repository\QuestionCategoryDescriptionRepository;
use App\Repository\QuestionRepository;
use App\Repository\SubjectRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use thiagoalessio\TesseractOCR\TesseractOCR;

#[Route('/evaluation')]
class EvaluationController extends AbstractController
{
    public function __construct(
        private AuditLogger $audit,
        private MailerInterface $mailer,
        private SubjectRepository $subjectRepo,
        private UserRepository $userRepo,
        private CurriculumRepository $curriculumRepo,
        private AcademicYearRepository $academicYearRepo,
        private LoadslipVerificationRepository $loadslipVerificationRepo,
    ) {}

    // ════════════════════════════════════════════════
    //  SET — Student Evaluation for Teacher
    // ════════════════════════════════════════════════

    /**
     * Normalize year-level strings so "4th Year" matches "Fourth Year", etc.
     */
    private function normalizeYearLevel(?string $yl): ?string
    {
        if ($yl === null) return null;
        $map = [
            '1st year' => 'First Year',  'first year' => 'First Year',
            '2nd year' => 'Second Year', 'second year' => 'Second Year',
            '3rd year' => 'Third Year',  'third year' => 'Third Year',
            '4th year' => 'Fourth Year', 'fourth year' => 'Fourth Year',
        ];
        return $map[strtolower(trim($yl))] ?? $yl;
    }

    private function normalizeSubjectCode(string $code): string
    {
        $value = strtoupper(trim((string) preg_replace('/\s+/', ' ', $code)));
        if ($value === '') {
            return '';
        }

        $value = strtr($value, ['|' => '1', '!' => '1']);
        $value = (string) preg_replace('/[^A-Z0-9\s-]/', ' ', $value);
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        $prefixRaw = '';
        $suffixRaw = '';

        if (preg_match('/^([A-Z0-9]{2,8})\s*-?\s*([A-Z0-9]{1,6})$/u', $value, $m)) {
            $prefixRaw = (string) ($m[1] ?? '');
            $suffixRaw = (string) ($m[2] ?? '');
        } else {
            $compact = (string) preg_replace('/[^A-Z0-9]/u', '', $value);
            if (preg_match('/^([A-Z0-9]{2,8}?)([0-9OQDILSZGB]{2,4}[A-Z]?)$/u', $compact, $m)) {
                $prefixRaw = (string) ($m[1] ?? '');
                $suffixRaw = (string) ($m[2] ?? '');
            }
        }

        if ($prefixRaw === '' || $suffixRaw === '') {
            $compactFallback = (string) preg_replace('/[^A-Z0-9]/u', '', $value);
            if (preg_match('/^(?:IS|ISO|ISTO|ITSO|1S|1SO)(\d{1,3}[A-Z]?)$/u', $compactFallback, $mFix)) {
                $num = str_pad((string) ($mFix[1] ?? ''), 3, '0', STR_PAD_LEFT);
                return 'ITS ' . $num;
            }
            return $value;
        }

        $prefix = strtr($prefixRaw, [
            '0' => 'O',
            '1' => 'I',
            '5' => 'S',
            '8' => 'B',
            '2' => 'Z',
            '6' => 'G',
        ]);
        $prefix = (string) preg_replace('/[^A-Z]/u', '', $prefix);

        $suffix = strtr(strtoupper($suffixRaw), [
            'O' => '0',
            'Q' => '0',
            'D' => '0',
            'I' => '1',
            'L' => '1',
            'S' => '5',
            'Z' => '2',
            'G' => '6',
            'B' => '8',
        ]);
        $suffix = (string) preg_replace('/[^A-Z0-9]/u', '', $suffix);

        if (in_array($prefix, ['IS', 'ISO'], true)) {
            $prefix = 'ITS';
        }

        // OCR often drops the leading 4 for BSIT capstone/multimedia rows: IS3/ITS05 -> ITS403/ITS405.
        if ($prefix === 'ITS' && preg_match('/^(\d{1,2})$/u', $suffix, $mShortNum)) {
            $digits = str_pad((string) ($mShortNum[1] ?? ''), 2, '0', STR_PAD_LEFT);
            if ($digits !== '00') {
                $suffix = '4' . $digits;
            }
        }

        if ($prefix === '' || $suffix === '') {
            return $value;
        }

        return trim($prefix . ' ' . $suffix);
    }

    private function normalizeLoadslipDescription(string $description, ?string $rowCode = null): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $description));
        if ($value === '') {
            return '';
        }

        $normalizedRowCode = $this->normalizeSubjectCode((string) $rowCode);

        $value = (string) preg_replace_callback(
            '/\b([A-Z0-9]{2,8}\s*-?\s*[A-Z0-9]{1,6}|[A-Z]{2,8}[0-9]{1,6})\b/u',
            function (array $m) use ($normalizedRowCode): string {
                $candidateRaw = (string) ($m[1] ?? '');
                $candidate = $this->normalizeSubjectCode($candidateRaw);
                if ($candidate === '') {
                    return $candidateRaw;
                }

                if ($normalizedRowCode !== '' && $this->subjectCodesAreCompatible($normalizedRowCode, $candidate)) {
                    return $normalizedRowCode;
                }

                return $candidateRaw;
            },
            $value
        );

        if ($normalizedRowCode !== '') {
            $value = (string) preg_replace('/^\s*' . preg_quote($normalizedRowCode, '/') . '\s*(?:[-:|]\s*)?/u', '', $value, 1);
        }

        // Drop OCR day fragments that often bleed into description tail (e.g., "... TH").
        $value = (string) preg_replace('/(?:\s+(?:M|T|W|TH|F|S|SU|MWF|TTH|THF))+\s*$/u', '', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value), " \t\n\r\0\x0B-:|,");

        return $value;
    }

    private function sanitizeLoadslipTraceSource(string $value): string
    {
        $source = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($source === '') {
            return '';
        }

        // Remove explicit room labels while keeping neighboring context readable.
        $source = (string) preg_replace('/\b(?:ROOM|RM)\s*[:\-]?\s*[A-Z0-9\-\/ ]{1,20}\b/u', '', $source);

        // Remove room tokens commonly placed between schedule and units in OCR rows.
        $source = (string) preg_replace(
            '/((?:[A-Z]{1,3}(?:\s*-\s*[A-Z]{1,3}){1,2}|MWF|TTH|THF)\s+\d{1,2}(?:(?::|\.)\d{2})?\s*-\s*\d{1,2}(?:(?::|\.)\d{2})?\s*(?:A\.?M\.?|P\.?M\.?|AM|PM)?|\d{1,2}(?:(?::|\.)\d{2})?\s*-\s*\d{1,2}(?:(?::|\.)\d{2})?\s*(?:A\.?M\.?|P\.?M\.?|AM|PM)?)\s+[A-Z][A-Z0-9\s\-]{1,24}\s+(\d+(?:\.\d+)?)\b/u',
            '$1 $2',
            $source
        );

        return trim((string) preg_replace('/\s+/u', ' ', $source));
    }

    private function compactSubjectCode(string $code): string
    {
        return (string) preg_replace('/[^A-Z0-9]/u', '', strtoupper($this->normalizeSubjectCode($code)));
    }

    private function extractSubjectCodeNumericTail(string $code): string
    {
        $compact = $this->compactSubjectCode($code);
        if ($compact === '') {
            return '';
        }

        if (preg_match('/(\d{2,4}[A-Z]?)$/u', $compact, $m)) {
            return (string) ($m[1] ?? '');
        }

        return '';
    }

    private function extractSubjectCodeLooseNumericTail(string $code): string
    {
        $compact = $this->compactSubjectCode($code);
        if ($compact === '') {
            return '';
        }

        if (preg_match('/(\d{1,4}[A-Z]?)$/u', $compact, $m)) {
            return (string) ($m[1] ?? '');
        }

        return '';
    }

    private function resolveToKnownSubjectCode(string $candidate, array $knownCodeByCompact): string
    {
        $normalized = $this->normalizeSubjectCode($candidate);
        $compact = $this->compactSubjectCode($normalized);
        if ($compact === '' || empty($knownCodeByCompact)) {
            return '';
        }

        if (isset($knownCodeByCompact[$compact])) {
            return (string) $knownCodeByCompact[$compact];
        }

        $candidateTail = $this->extractSubjectCodeNumericTail($compact);
        $candidateLooseTail = $this->extractSubjectCodeLooseNumericTail($compact);
        $candidatePrefix = $this->extractSubjectCodePrefix($compact);
        $bestMatch = '';
        $bestScore = PHP_INT_MAX;

        foreach ($knownCodeByCompact as $knownCompact => $knownCode) {
            if (!is_string($knownCompact) || !is_string($knownCode)) {
                continue;
            }

            $knownTail = $this->extractSubjectCodeNumericTail($knownCompact);
            $knownPrefix = $this->extractSubjectCodePrefix($knownCompact);

            if ($candidateTail !== '' && $knownTail !== '' && $candidateTail !== $knownTail) {
                continue;
            }

            if ($candidateTail === '' && $candidateLooseTail !== '' && $knownTail !== '' && strlen($candidateLooseTail) <= 2) {
                if (!str_ends_with($knownTail, $candidateLooseTail)) {
                    continue;
                }
            }

            $distance = levenshtein($compact, $knownCompact);
            $maxLen = max(strlen($compact), strlen($knownCompact));
            $maxAllowedDistance = $maxLen >= 7 ? 2 : 1;

            if ($candidatePrefix !== '' && $knownPrefix !== '' && levenshtein($candidatePrefix, $knownPrefix) > 2) {
                continue;
            }

            if ($candidateTail === '' && $candidateLooseTail !== '' && strlen($candidateLooseTail) <= 2) {
                $maxAllowedDistance = max($maxAllowedDistance, 3);
            }

            if ($distance <= $maxAllowedDistance && $distance < $bestScore) {
                $bestScore = $distance;
                $bestMatch = $knownCode;
            }
        }

        return $bestMatch;
    }

    private function applyLoadslipSubjectCodeDescriptionHeuristic(string $code, string $description): string
    {
        $normalizedCode = $this->normalizeSubjectCode($code);
        if (!$this->isLikelySubjectCode($normalizedCode)) {
            return $normalizedCode;
        }

        $desc = strtoupper(trim((string) preg_replace('/\s+/u', ' ', $description)));
        $desc = (string) preg_replace('/[^A-Z0-9\s]/u', ' ', $desc);
        $desc = trim((string) preg_replace('/\s+/u', ' ', $desc));
        if ($desc === '') {
            return $normalizedCode;
        }

        // Description-first recovery for rows where OCR misreads the code cell.
        if (preg_match('/\bCAPSTONE\b.*\bPROJECT\b.*\b2\b/u', $desc)) {
            return 'ITS 403';
        }

        if (preg_match('/\bSOCIAL\b.*\bPROFESSIONAL\b/u', $desc) && preg_match('/\bISSUES?\b/u', $desc)) {
            return 'ITS 404';
        }

        if (preg_match('/\bMULTIMEDIA\b/u', $desc) && preg_match('/\bSYSTEMS?\b/u', $desc)) {
            return 'ITS 405';
        }

        // OCR can flip the last digit for BSIT capstone rows; description is a stronger signal.
        if ($normalizedCode === 'ITS 402' && preg_match('/\bCAPSTONE\b.*\bPROJECT\b.*\b2\b/u', $desc)) {
            return 'ITS 403';
        }

        return $normalizedCode;
    }

    private function buildKnownSubjectCodeByCompact(SubjectRepository $subjectRepo, array $preferredCodeMap = []): array
    {
        $knownCodeByCompact = [];
        $hasPreferred = !empty($preferredCodeMap);

        foreach ($subjectRepo->findAll() as $subject) {
            $normalizedSubjectCode = $this->normalizeSubjectCode((string) $subject->getSubjectCode());
            if ($normalizedSubjectCode === '') {
                continue;
            }

            $compact = $this->compactSubjectCode($normalizedSubjectCode);
            if ($compact === '') {
                continue;
            }

            if ($hasPreferred && isset($preferredCodeMap[$normalizedSubjectCode])) {
                $knownCodeByCompact[$compact] = $normalizedSubjectCode;
                continue;
            }

            if (!isset($knownCodeByCompact[$compact])) {
                $knownCodeByCompact[$compact] = $normalizedSubjectCode;
            }
        }

        return $knownCodeByCompact;
    }

    private function getCurriculumSubjectCodeMapForUser(?\App\Entity\User $user, CurriculumRepository $curriculumRepo): array
    {
        if (!$user || !$user->isStudent()) {
            return [];
        }

        $courseId = $user->getCourse()?->getId();
        $departmentId = $user->getDepartment()?->getId();
        $codeMap = [];

        foreach ($curriculumRepo->findAll() as $curriculum) {
            $curriculumCourse = $curriculum->getCourse();
            if ($curriculumCourse !== null && ($courseId === null || $curriculumCourse->getId() !== $courseId)) {
                continue;
            }

            $curriculumDepartment = $curriculum->getDepartment();
            if ($curriculumDepartment !== null && ($departmentId === null || $curriculumDepartment->getId() !== $departmentId)) {
                continue;
            }

            foreach ($curriculum->getSubjects() as $subject) {
                $normalizedCode = $this->normalizeSubjectCode((string) $subject->getSubjectCode());
                if ($normalizedCode !== '') {
                    $codeMap[$normalizedCode] = true;
                }
            }
        }

        return $codeMap;
    }

    private function isLikelySubjectCode(string $value): bool
    {
        return (bool) preg_match('/^[A-Z]{2,}\s\d{1,4}[A-Z]?$/u', $this->normalizeSubjectCode($value));
    }

    private function extractSubjectCodePrefix(string $value): string
    {
        $normalized = $this->normalizeSubjectCode($value);
        if (preg_match('/^([A-Z]{2,8})\s\d/u', $normalized, $m)) {
            return (string) ($m[1] ?? '');
        }

        return '';
    }

    private function isLikelyOcrSubjectCode(string $value): bool
    {
        $normalized = $this->normalizeSubjectCode($value);
        if (!$this->isLikelySubjectCode($normalized)) {
            return false;
        }

        $tail = $this->extractSubjectCodeNumericTail($normalized);
        if (strlen($tail) < 3) {
            return false;
        }

        $prefix = $this->extractSubjectCodePrefix($normalized);
        if ($prefix === '') {
            return false;
        }

        $noisePrefixes = [
            'DATE', 'PRINTED', 'ENCODED', 'DESCRIPT', 'ANONYMOU', 'ANONYMOUS',
            'PROJECT', 'MULTIMED', 'ENROLLE', 'BIRTH', 'STUDENT', 'SCHOLAR',
            'SECTION', 'SUBJECT', 'SCHEDULE', 'ROOM', 'UNITS', 'TOTAL',
        ];

        return !in_array($prefix, $noisePrefixes, true);
    }

    private function normalizeHeaderName(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value)));
    }

    private function normalizeStudentNumber(string $value): string
    {
        $value = strtoupper(trim($value));
        // Normalize common OCR confusions before stripping non-digits.
        $value = str_replace(
            ['O', 'Q', 'D', 'I', 'L', '|', '!', 'S', 'B', 'Z', 'G'],
            ['0', '0', '0', '1', '1', '1', '1', '5', '8', '2', '6'],
            $value
        );
        return (string) preg_replace('/[^0-9]/', '', $value);
    }

    private function normalizeLoadslipPreviewPath(?string $value): string
    {
        $path = trim((string) $value);
        if ($path === '' || str_contains($path, '..')) {
            return '';
        }

        $path = str_replace('\\', '/', $path);
        $path = (string) preg_replace('#/+#', '/', $path);
        $path = ltrim($path, '/');

        if ($path === '' || preg_match('/^[A-Z]:/iu', $path) === 1) {
            return '';
        }

        return $path;
    }

    private function getAbsolutePublicPath(string $relativePath): string
    {
        $projectDir = rtrim((string) $this->getParameter('kernel.project_dir'), '\\/');
        return $projectDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $relativePath), '\\/');
    }

    private function loadslipPreviewPathExists(string $path): bool
    {
        $normalizedPath = $this->normalizeLoadslipPreviewPath($path);
        if ($normalizedPath === '') {
            return false;
        }

        return is_file($this->getAbsolutePublicPath($normalizedPath));
    }
    
    private function isLoadslipVerifiedForStudent(Request $request, string $schoolId): bool
    {
        $session = $request->getSession();
        $normalizedSchoolId = $this->normalizeStudentNumber($schoolId);
        if ($normalizedSchoolId === '') {
            return false;
        }

        $persisted = $this->readLoadslipVerificationData($schoolId);
        if ($persisted !== null && !$this->isStoredLoadslipAcademicTermCurrent($persisted)) {
            $this->clearStudentLoadslipState($request, $schoolId, (string) ($persisted['previewPath'] ?? ''));
            return false;
        }

        $codes = (array) $session->get('student_loadslip_codes', []);
        $rows = (array) $session->get('student_loadslip_rows', []);
        $normalizedSessionStudentNumber = $this->normalizeStudentNumber((string) $session->get('student_loadslip_student_number', ''));

        // Stale session from a different student should not block current verification.
        if ($normalizedSessionStudentNumber !== '' && $normalizedSessionStudentNumber !== $normalizedSchoolId) {
            $this->clearStudentLoadslipState($request);
            $codes = [];
            $rows = [];
            $normalizedSessionStudentNumber = '';
        }

        // Recover verification state for QR/public flows where session may not include imported data.
        if (empty($codes) && empty($rows)) {
            if ($persisted !== null) {
                $codes = (array) ($persisted['codes'] ?? []);
                $rows = (array) ($persisted['rows'] ?? []);
                $session->set('student_loadslip_codes', $codes);
                $session->set('student_loadslip_rows', $rows);
                $session->set('student_loadslip_student_number', (string) ($persisted['studentNumber'] ?? ''));
                $session->set('student_loadslip_verified', (bool) ($persisted['verified'] ?? true));
                $persistedPreviewPath = $this->normalizeLoadslipPreviewPath((string) ($persisted['previewPath'] ?? ''));
                if ($persistedPreviewPath !== '' && $this->loadslipPreviewPathExists($persistedPreviewPath)) {
                    $session->set('student_loadslip_preview_path', $persistedPreviewPath);
                }
                $normalizedSessionStudentNumber = $this->normalizeStudentNumber((string) ($persisted['studentNumber'] ?? ''));
            }
        }

        if (empty($codes) && empty($rows)) {
            return false;
        }

        $normalizedLoadslipStudentNumber = $normalizedSessionStudentNumber;

        // If rows/codes exist but student number marker is missing, recover authoritative persisted data.
        if ($normalizedLoadslipStudentNumber === '') {
            if ($persisted !== null) {
                $codes = (array) ($persisted['codes'] ?? []);
                $rows = (array) ($persisted['rows'] ?? []);
                $normalizedLoadslipStudentNumber = $this->normalizeStudentNumber((string) ($persisted['studentNumber'] ?? ''));
                $session->set('student_loadslip_codes', $codes);
                $session->set('student_loadslip_rows', $rows);
                $session->set('student_loadslip_student_number', (string) ($persisted['studentNumber'] ?? ''));
                $session->set('student_loadslip_verified', (bool) ($persisted['verified'] ?? true));
                $persistedPreviewPath = $this->normalizeLoadslipPreviewPath((string) ($persisted['previewPath'] ?? ''));
                if ($persistedPreviewPath !== '' && $this->loadslipPreviewPathExists($persistedPreviewPath)) {
                    $session->set('student_loadslip_preview_path', $persistedPreviewPath);
                }
            }
        }

        // Preferred path: verify by matching the parsed loadslip student number.
        if ($normalizedLoadslipStudentNumber !== '') {
            $matched = $normalizedLoadslipStudentNumber === $normalizedSchoolId;
            if ($matched) {
                // Self-heal stale sessions where the boolean flag may be missing.
                $session->set('student_loadslip_verified', true);

                // If preview path is missing/invalid but verification data exists, restore it from persisted JSON.
                $sessionPreviewPath = $this->normalizeLoadslipPreviewPath((string) $session->get('student_loadslip_preview_path', ''));
                if ($sessionPreviewPath === '' || !$this->loadslipPreviewPathExists($sessionPreviewPath)) {
                    if ($persisted !== null) {
                        $persistedPreviewPath = $this->normalizeLoadslipPreviewPath((string) ($persisted['previewPath'] ?? ''));
                        if ($persistedPreviewPath !== '' && $this->loadslipPreviewPathExists($persistedPreviewPath)) {
                            $session->set('student_loadslip_preview_path', $persistedPreviewPath);
                        } else {
                            $session->remove('student_loadslip_preview_path');
                        }
                    }
                }
            }
            return $matched;
        }

        $verifiedFlag = (bool) $session->get('student_loadslip_verified', false);

        if ($verifiedFlag) {
            if ($persisted !== null) {
                $session->set('student_loadslip_codes', (array) ($persisted['codes'] ?? []));
                $session->set('student_loadslip_rows', (array) ($persisted['rows'] ?? []));
                $session->set('student_loadslip_student_number', (string) ($persisted['studentNumber'] ?? ''));
                $session->set('student_loadslip_verified', (bool) ($persisted['verified'] ?? true));
                $persistedPreviewPath = $this->normalizeLoadslipPreviewPath((string) ($persisted['previewPath'] ?? ''));
                if ($persistedPreviewPath !== '' && $this->loadslipPreviewPathExists($persistedPreviewPath)) {
                    $session->set('student_loadslip_preview_path', $persistedPreviewPath);
                }
                return true;
            }
        }

        // Backward compatibility for sessions created before student_loadslip_student_number/flag existed.
        // If the currently authenticated student matches the requested ID and loadslip data exists,
        // recover the verification flag for this session.
        $currentUser = $this->getUser();
        if ($currentUser && method_exists($currentUser, 'isStudent') && $currentUser->isStudent()) {
            $currentUserSchoolId = $this->normalizeStudentNumber((string) ($currentUser->getSchoolId() ?? ''));
            if ($currentUserSchoolId !== '' && $currentUserSchoolId === $normalizedSchoolId) {
                $session->set('student_loadslip_verified', true);
                return true;
            }
        }

        return false;
    }

    private function normalizeSectionValue(?string $value): string
    {
        $raw = strtoupper(trim((string) $value));
        return (string) preg_replace('/[^A-Z0-9]/u', '', $raw);
    }

    private function normalizeSectionTokenFromOcr(string $value, string $subjectCode = ''): string
    {
        $token = $this->normalizeSectionValue($value);
        if ($token === '') {
            return '';
        }

        // OCR frequently turns letters into digits for section tokens (e.g., B -> 8, O -> 0).
        $digitToLetter = [
            '0' => 'O',
            '8' => 'B',
            '5' => 'S',
            '6' => 'G',
        ];

        if (strlen($token) === 1 && isset($digitToLetter[$token])) {
            $token = (string) $digitToLetter[$token];
        } elseif ((bool) preg_match('/[A-Z]/u', $token)) {
            $token = strtr($token, $digitToLetter);
        }

        // Collapse obvious doubled OCR section tokens like "CC" -> "C".
        if (preg_match('/^([A-Z0-9])\1{1,5}$/u', $token, $dupMatch)) {
            $token = (string) ($dupMatch[1] ?? $token);
        }

        // Sections are letter-based in this loadslip format; drop unresolved numeric OCR output.
        if ((bool) preg_match('/\d/u', $token)) {
            return '';
        }

        if (!$this->isLikelySectionToken($token, $subjectCode)) {
            return '';
        }

        return $token;
    }

    private function isLikelySectionToken(string $value, string $subjectCode = ''): bool
    {
        $token = $this->normalizeSectionValue($value);
        if ($token === '' || strlen($token) > 6) {
            return false;
        }

        // Reject numeric-bearing tokens (e.g., "1", "B1") for section values.
        if ((bool) preg_match('/\d/u', $token)) {
            return false;
        }

        if (in_array($token, ['M', 'T', 'W', 'TH', 'F', 'S', 'SU', 'MWF', 'TTH', 'THF', 'AM', 'PM'], true)) {
            return false;
        }

        if ((bool) preg_match('/^[A-Z]{3,}$/u', $token)) {
            return false;
        }

        $subjectPrefix = $this->extractSubjectCodePrefix($subjectCode);
        if ($subjectPrefix !== '' && $token === $subjectPrefix) {
            return false;
        }

        if (preg_match('/^\d{3,4}$/u', $token)) {
            $tail = $this->extractSubjectCodeNumericTail($subjectCode);
            if ($tail !== '' && $tail === $token) {
                return false;
            }
        }

        return (bool) preg_match('/^[A-Z0-9\-]{1,6}$/u', $token);
    }

    private function getLoadslipScheduleSubpattern(): string
    {
        $dayToken = '(?:MON|TUE|WED|THU|FRI|SAT|SUN|M|T|W|TH|F|S|SU|MWF|TTH|THF)';
        $timeToken = '(?:\d{1,2}(?:(?::|\.)\d{2})?\s*(?:A\.?M\.?|P\.?M\.?|AM|PM)?)';

        return '(?:(?:' . $dayToken . '(?:\s*-\s*' . $dayToken . '){0,2})\s+' . $timeToken . '\s*-\s*' . $timeToken . '|' . $timeToken . '\s*-\s*' . $timeToken . ')';
    }

    private function getLoadslipSchedulePattern(): string
    {
        return '/\b(' . $this->getLoadslipScheduleSubpattern() . ')\b/u';
    }

    private function parseOcrScheduleTimeToken(string $value): ?array
    {
        $token = strtoupper(trim($value));
        if ($token === '') {
            return null;
        }

        if (!preg_match('/^(\d{1,2})(?:(?::|\.)\s*(\d{2}))?\s*(A\.?M\.?|P\.?M\.?|AM|PM)?$/u', $token, $m)) {
            return null;
        }

        $hour = (int) ($m[1] ?? -1);
        $minute = (int) (($m[2] ?? '') !== '' ? $m[2] : 0);
        if ($hour < 0 || $minute < 0 || $minute > 59) {
            return null;
        }

        $meridiem = strtoupper(trim((string) ($m[3] ?? '')));
        $meridiem = str_replace('.', '', $meridiem);
        if ($meridiem !== '' && !in_array($meridiem, ['AM', 'PM'], true)) {
            return null;
        }

        if ($meridiem !== '') {
            if ($hour < 1 || $hour > 12) {
                return null;
            }
        } elseif ($hour > 23) {
            return null;
        }

        return [
            'hour' => $hour,
            'minute' => $minute,
            'meridiem' => $meridiem,
        ];
    }

    private function validateScheduleCandidate(string $candidate): ?array
    {
        $candidate = strtoupper(trim($candidate));
        if ($candidate === '') {
            return null;
        }

        $candidate = str_replace(["\xE2\x80\x93", "\xE2\x80\x94"], '-', $candidate);
        $candidate = (string) preg_replace('/(\d)\.(\d{2})/u', '$1:$2', $candidate);
        $candidate = (string) preg_replace('/\s+/u', ' ', $candidate);

        $dayToken = '(?:MON|TUE|WED|THU|FRI|SAT|SUN|M|T|W|TH|F|S|SU|MWF|TTH|THF)';
        if (!preg_match('/^(?:(?<days>' . $dayToken . '(?:\s*-\s*' . $dayToken . '){0,2})\s+)?(?<start>\d{1,2}(?::\d{2})?\s*(?:A\.?M\.?|P\.?M\.?|AM|PM)?)\s*-\s*(?<end>\d{1,2}(?::\d{2})?\s*(?:A\.?M\.?|P\.?M\.?|AM|PM)?)$/u', $candidate, $m)) {
            return null;
        }

        $start = $this->parseOcrScheduleTimeToken((string) ($m['start'] ?? ''));
        $end = $this->parseOcrScheduleTimeToken((string) ($m['end'] ?? ''));
        if (!is_array($start) || !is_array($end)) {
            return null;
        }

        // Treat 00:xx OCR time fragments as invalid for class schedules.
        if (((int) ($start['hour'] ?? -1)) === 0 || ((int) ($end['hour'] ?? -1)) === 0) {
            return null;
        }

        $hasMeridiem = ((string) ($start['meridiem'] ?? '')) !== '' || ((string) ($end['meridiem'] ?? '')) !== '';
        if ($hasMeridiem && ((int) ($start['hour'] ?? 0) > 12 || (int) ($end['hour'] ?? 0) > 12)) {
            return null;
        }

        return [
            'text' => trim((string) preg_replace('/\s+/u', ' ', $candidate)),
            'hasDay' => trim((string) ($m['days'] ?? '')) !== '',
            'hasMeridiem' => $hasMeridiem,
        ];
    }

    private function extractScheduleFromText(string $value, string $schedulePattern): string
    {
        $value = strtoupper($value);
        $value = str_replace(["\xE2\x80\x93", "\xE2\x80\x94"], '-', $value);
        $value = (string) preg_replace('/(\d)\.(\d{2})/u', '$1:$2', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);
        if (!preg_match_all($schedulePattern, $value, $matches)) {
            return '';
        }

        $best = '';
        $bestScore = -1;
        foreach (($matches[1] ?? []) as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            $validated = $this->validateScheduleCandidate($candidate);
            if (!is_array($validated)) {
                continue;
            }

            $score = 0;
            if ((bool) ($validated['hasDay'] ?? false)) {
                $score += 4;
            }
            if ((bool) ($validated['hasMeridiem'] ?? false)) {
                $score += 2;
            }
            if (preg_match('/\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}/u', (string) ($validated['text'] ?? ''))) {
                $score++;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (string) ($validated['text'] ?? '');
            }
        }

        return $best;
    }

    private function detectLoadslipTableWindow(array $lines, string $schedulePattern): ?array
    {
        if (empty($lines)) {
            return null;
        }

        $headerIndex = null;
        foreach ($lines as $idx => $lineRaw) {
            $line = strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $lineRaw)));
            if ($line === '') {
                continue;
            }

            $hasHeaderTokens = preg_match('/\bSUBJ(?:ECT)?\b/u', $line)
                && preg_match('/\bSECTION\b/u', $line)
                && preg_match('/\bDESCRIPT(?:ION)?\b/u', $line)
                && preg_match('/\bSCHEDULE\b/u', $line)
                && preg_match('/\bUNITS?\b/u', $line);

            if ($hasHeaderTokens) {
                $headerIndex = (int) $idx;
                break;
            }
        }

        if ($headerIndex === null) {
            return null;
        }

        $lineCount = count($lines);
        $start = min($lineCount - 1, $headerIndex + 1);
        $maxLookahead = min($lineCount - 1, $start + 42);

        $seenRow = false;
        $lastRowIndex = $start - 1;
        $misses = 0;

        for ($i = $start; $i <= $maxLookahead; $i++) {
            $lineRaw = trim((string) ($lines[$i] ?? ''));
            if ($lineRaw === '') {
                if ($seenRow) {
                    $misses++;
                    if ($misses >= 2) {
                        break;
                    }
                }
                continue;
            }

            $line = strtoupper((string) preg_replace('/\s+/u', ' ', $lineRaw));
            if (preg_match('/\b(?:NOTE\s*:|STUDENT,?\s*PARENT|ENCODED\s+BY|PRINTED\s+BY|DATE\s+ENROLLED|TOTAL\s+SUBJECTS?|TOTAL\s+UNITS?)\b/u', $line)) {
                if ($seenRow) {
                    break;
                }
                continue;
            }

            $hasCodeLike = (bool) preg_match('/\b([A-Z]{2,8}\s*-?\s*[0-9OQDILSZGB]{1,4}[A-Z]?|[A-Z0-9]{2,8}\s*-?\s*[A-Z0-9]{1,6}|[A-Z0-9]{4,12})\b/u', $line);
            $hasScheduleLike = $this->extractScheduleFromText($line, $schedulePattern) !== '';

            if ($hasCodeLike && $hasScheduleLike) {
                $seenRow = true;
                $lastRowIndex = $i;
                $misses = 0;
                continue;
            }

            if ($seenRow) {
                $misses++;
                if ($misses >= 3) {
                    break;
                }
            }
        }

        if (!$seenRow) {
            return [
                'header' => $headerIndex,
                'start' => $start,
                'end' => min($lineCount - 1, $start + 18),
                'hasRows' => false,
            ];
        }

        return [
            'header' => $headerIndex,
            'start' => $start,
            'end' => max($start, $lastRowIndex),
            'hasRows' => true,
        ];
    }

    private function extractOcrRowFromLine(string $line, string $schedulePattern): ?array
    {
        $rawLine = trim($line);
        if ($rawLine === '') {
            return null;
        }

        $lineUpper = strtoupper((string) preg_replace('/\s+/u', ' ', $rawLine));
        $lineUpper = str_replace(["\xE2\x80\x93", "\xE2\x80\x94"], '-', $lineUpper);
        $lineUpper = (string) preg_replace('/(\d)\.(\d{2})/u', '$1:$2', $lineUpper);
        $schedule = $this->extractScheduleFromText($lineUpper, $schedulePattern);
        if ($schedule === '') {
            return null;
        }

        $beforeSchedule = trim((string) strstr($lineUpper, $schedule, true));
        if ($beforeSchedule === '') {
            $beforeSchedule = $lineUpper;
        }

        $columns = array_values(array_filter(array_map('trim', preg_split('/\t+|\s{2,}/u', $lineUpper) ?: []), static fn(string $v): bool => $v !== ''));

        $rawCode = '';
        $codeCandidates = [];
        if (!empty($columns)) {
            $codeCandidates[] = (string) ($columns[0] ?? '');
        }
        if (preg_match_all('/\b([A-Z]{2,8}\s*-?\s*[0-9OQDILSZGB]{1,4}[A-Z]?)\b/u', $beforeSchedule, $mStrictCodes)) {
            foreach (($mStrictCodes[1] ?? []) as $token) {
                $codeCandidates[] = (string) $token;
            }
        }
        if (preg_match('/^\s*([A-Z0-9]{3,12}|[A-Z0-9]{2,8}\s*-?\s*[A-Z0-9]{1,6})\b/u', $beforeSchedule, $m)) {
            $codeCandidates[] = (string) ($m[1] ?? '');
        }

        if (preg_match_all('/\b([A-Z0-9]{2,8}\s*-?\s*[A-Z0-9]{1,6}|[A-Z]{2,8}[0-9]{1,4}[A-Z]?)\b/u', $beforeSchedule, $mAll)) {
            foreach (($mAll[1] ?? []) as $token) {
                $codeCandidates[] = (string) $token;
            }
        }

        $codeCandidates = array_values(array_unique(array_map(static fn($c): string => trim((string) $c), $codeCandidates)));

        for ($idx = count($codeCandidates) - 1; $idx >= 0; $idx--) {
            $candidate = trim((string) ($codeCandidates[$idx] ?? ''));
            if ($candidate === '') {
                continue;
            }
            if ($this->isLikelyOcrSubjectCode($candidate)) {
                $rawCode = $candidate;
                break;
            }
        }

        if ($rawCode === '') {
            foreach ($codeCandidates as $candidate) {
                $candidate = trim((string) $candidate);
                if ($candidate === '') {
                    continue;
                }
                if ($this->isLikelySubjectCode($candidate)) {
                    $rawCode = $candidate;
                    break;
                }
                if (preg_match('/\b([A-Z0-9]{2,8}\s*-?\s*[A-Z0-9]{1,6}|[A-Z0-9]{4,12})\b/u', $candidate, $mToken) && $this->isLikelySubjectCode((string) ($mToken[1] ?? ''))) {
                    $rawCode = (string) ($mToken[1] ?? '');
                    break;
                }
            }
        }

        if ($rawCode === '') {
            return null;
        }

        $code = $this->normalizeSubjectCode($rawCode);
        if (!$this->isLikelySubjectCode($code)) {
            return null;
        }

        $section = '';
        $sectionSource = 'missing';
        if (count($columns) > 1 && isset($columns[0]) && $this->normalizeSubjectCode((string) $columns[0]) === $code) {
            $columnSection = $this->normalizeSectionTokenFromOcr((string) ($columns[1] ?? ''), $code);
            if ($this->isLikelySectionToken($columnSection, $code)) {
                $section = $columnSection;
                $sectionSource = 'column';
            }
        }

        $descriptionSource = 'before_schedule';
        $left = '';
        $rawCodePos = strpos($beforeSchedule, $rawCode);
        if ($rawCodePos !== false) {
            $left = trim((string) substr($beforeSchedule, $rawCodePos + strlen($rawCode)));
        }
        if ($left === '') {
            $left = trim((string) preg_replace('/^\s*' . preg_quote($rawCode, '/') . '\b/u', '', $beforeSchedule, 1));
        }
        if ($section === '' && $left !== '' && preg_match('/^([A-Z0-9\-]{1,6})\b/u', $left, $mSec)) {
            $secCandidate = $this->normalizeSectionTokenFromOcr((string) ($mSec[1] ?? ''), $code);
            if ($this->isLikelySectionToken($secCandidate, $code)) {
                $section = $secCandidate;
                $sectionSource = 'inline_after_code';
            }
        }

        if ($section === '' && preg_match('/\bSEC(?:TION)?\s*[:\-]?\s*([A-Z0-9\-]{1,6})\b/u', $beforeSchedule, $mSecLabel)) {
            $secCandidate = $this->normalizeSectionTokenFromOcr((string) ($mSecLabel[1] ?? ''), $code);
            if ($this->isLikelySectionToken($secCandidate, $code)) {
                $section = $secCandidate;
                $sectionSource = 'section_label';
            }
        }

        if ($section !== '') {
            $sectionPattern = preg_quote($section, '/');
            $trimmedLeft = (string) preg_replace('/^(?:' . $sectionPattern . '(?:(?:\b|(?=\s|[:\-|\.]))\s*(?:[:\-|\.]\s*)?))+/u', '', $left, 1);
            if ($trimmedLeft !== $left) {
                $left = trim($trimmedLeft);
                $descriptionSource = 'after_section_trim';
            }

            if (strlen($section) === 1) {
                $trimmedDoubled = (string) preg_replace('/^(?:' . $sectionPattern . '){2,}(?=\s|$)/u', '', $left, 1);
                if ($trimmedDoubled !== $left) {
                    $left = trim($trimmedDoubled);
                    $descriptionSource = 'after_double_section_trim';
                }
            }

            // OCR can duplicate section with a lookalike glyph (e.g., B -> 8) before description.
            if ($left !== '' && preg_match('/^([A-Z0-9])\b/u', $left, $dupSecMatch)) {
                $dupCandidate = $this->normalizeSectionTokenFromOcr((string) ($dupSecMatch[1] ?? ''), $code);
                if (
                    $dupCandidate !== ''
                    && $this->normalizeSectionValue($dupCandidate) === $this->normalizeSectionValue($section)
                ) {
                    $left = trim((string) preg_replace('/^[A-Z0-9]\b\s*/u', '', $left, 1));
                    $descriptionSource = 'after_ocr_section_duplicate_trim';
                }
            }
        }

        $left = (string) preg_replace('/^\s*[\.:|=\-\)\(]+\s*/u', '', $left);
        $left = (string) preg_replace('/\s+(?:M|T|W|TH|F|S|SU|MWF|TTH|THF)\s*$/u', '', $left);
        $left = trim((string) preg_replace('/\s+/u', ' ', $left));

        $units = '';
        if (preg_match('/\b(\d+(?:\.\d+)?)\s*$/u', $lineUpper, $unitMatch)) {
            $units = trim((string) ($unitMatch[1] ?? ''));
        }

        // Rebuild row columns by position when OCR keeps columns but mangles spacing in the full line.
        if (!empty($columns)) {
            $codeColumnIdx = null;
            foreach ($columns as $idx => $columnRaw) {
                $columnCode = $this->normalizeSubjectCode((string) $columnRaw);
                if ($columnCode !== '' && $this->subjectCodesAreCompatible($code, $columnCode)) {
                    $codeColumnIdx = $idx;
                    break;
                }
            }

            if ($codeColumnIdx !== null) {
                $scheduleByColumn = '';
                $scheduleColumnIdx = null;
                $scheduleColumnEndIdx = null;
                for ($idx = $codeColumnIdx + 1; $idx < count($columns); $idx++) {
                    $candidate = trim((string) ($columns[$idx] ?? ''));
                    if ($candidate === '') {
                        continue;
                    }

                    $columnSchedule = $this->extractScheduleFromText($candidate, $schedulePattern);
                    if ($columnSchedule === '' && ($idx + 1) < count($columns)) {
                        $joined = trim($candidate . ' ' . (string) ($columns[$idx + 1] ?? ''));
                        $columnSchedule = $this->extractScheduleFromText($joined, $schedulePattern);
                        if ($columnSchedule !== '') {
                            $scheduleColumnEndIdx = $idx + 1;
                        }
                    }

                    if ($columnSchedule !== '') {
                        $scheduleByColumn = $columnSchedule;
                        $scheduleColumnIdx = $idx;
                        if ($scheduleColumnEndIdx === null) {
                            $scheduleColumnEndIdx = $idx;
                        }
                        break;
                    }
                }

                if ($schedule === '' && $scheduleByColumn !== '') {
                    $schedule = $scheduleByColumn;
                }

                $sectionByColumn = '';
                $sectionIdx = $codeColumnIdx + 1;
                if (isset($columns[$sectionIdx])) {
                    $sectionCandidate = $this->normalizeSectionTokenFromOcr((string) $columns[$sectionIdx], $code);
                    if ($this->isLikelySectionToken($sectionCandidate, $code)) {
                        $sectionByColumn = $sectionCandidate;
                    }
                }
                if ($section === '' && $sectionByColumn !== '') {
                    $section = $sectionByColumn;
                    $sectionSource = 'column_positional';
                }

                $unitsByColumn = '';
                if ($scheduleColumnEndIdx !== null) {
                    for ($idx = count($columns) - 1; $idx > $scheduleColumnEndIdx; $idx--) {
                        $tail = trim((string) ($columns[$idx] ?? ''));
                        if ($tail === '') {
                            continue;
                        }
                        if (preg_match('/^\d+(?:\.\d+)?$/u', $tail) && (float) $tail <= 10.0) {
                            $unitsByColumn = $tail;
                            break;
                        }
                    }
                }
                if ($units === '' && $unitsByColumn !== '') {
                    $units = $unitsByColumn;
                }

                $descriptionByColumn = '';
                if ($scheduleColumnIdx !== null) {
                    $descStart = $codeColumnIdx + 1;
                    if ($sectionByColumn !== '' && $sectionIdx === $descStart) {
                        $descStart++;
                    }

                    $descEnd = $scheduleColumnIdx - 1;
                    if ($descEnd >= $descStart) {
                        $descParts = [];
                        for ($idx = $descStart; $idx <= $descEnd; $idx++) {
                            $part = trim((string) ($columns[$idx] ?? ''));
                            if ($part !== '') {
                                $descParts[] = $part;
                            }
                        }
                        $descriptionByColumn = trim(implode(' ', $descParts));
                    }
                }

                if ($descriptionByColumn !== '') {
                    if ($left === '') {
                        $left = $descriptionByColumn;
                        $descriptionSource = 'column_positional';
                    } elseif (mb_strlen($left) < 8 && mb_strlen($descriptionByColumn) > mb_strlen($left)) {
                        $left = $descriptionByColumn;
                        $descriptionSource = 'column_positional_override';
                    }
                }
            }
        }

        $normalizedSection = $this->normalizeSectionValue($section);
        $normalizedSchedule = $this->normalizeScheduleValue($schedule);
        $codeColumn = null;
        $sectionColumn = null;
        $descriptionColumn = null;
        $scheduleColumn = null;
        $unitsColumn = null;

        foreach ($columns as $idx => $columnRaw) {
            $column = trim((string) $columnRaw);
            if ($column === '') {
                continue;
            }

            $columnNo = $idx + 1;
            $columnCode = $this->normalizeSubjectCode($column);
            if ($codeColumn === null && $columnCode !== '' && $this->subjectCodesAreCompatible($code, $columnCode)) {
                $codeColumn = $columnNo;
            }

            if ($sectionColumn === null && $normalizedSection !== '' && $this->isLikelySectionToken($column, $code) && $this->normalizeSectionValue($column) === $normalizedSection) {
                $sectionColumn = $columnNo;
            }

            if ($scheduleColumn === null && $normalizedSchedule !== '') {
                $columnSchedule = $this->normalizeScheduleValue($column);
                if ($columnSchedule !== '' && $this->schedulesAreCompatible($normalizedSchedule, $columnSchedule)) {
                    $scheduleColumn = $columnNo;
                }
            }

            if ($unitsColumn === null && $units !== '' && preg_match('/^\d+(?:\.\d+)?$/u', $column) && trim($column) === $units) {
                $unitsColumn = $columnNo;
            }

            if ($descriptionColumn === null && $left !== '') {
                $descNeedle = mb_substr($left, 0, 24);
                if ($descNeedle !== '' && str_contains($column, $descNeedle)) {
                    $descriptionColumn = $columnNo;
                }
            }
        }

        return [
            'code' => $code,
            'section' => $section,
            'description' => $left,
            'schedule' => $schedule,
            'units' => $units,
            'sectionSource' => $sectionSource,
            'descriptionSource' => $descriptionSource,
            'columnCount' => count($columns),
            'columnsPreview' => array_slice($columns, 0, 8),
            'columnMap' => [
                'code' => $codeColumn,
                'section' => $sectionColumn,
                'description' => $descriptionColumn,
                'schedule' => $scheduleColumn,
                'units' => $unitsColumn,
            ],
        ];
    }

    private function normalizeWeekdayToken(string $token): string
    {
        $token = strtoupper(trim($token));
        return match ($token) {
            'MONDAY', 'MON', 'M' => 'M',
            'TUESDAY', 'TUES', 'TUE', 'TU', 'T' => 'T',
            'WEDNESDAY', 'WED', 'W' => 'W',
            'THURSDAY', 'THURS', 'THUR', 'THU', 'TH' => 'TH',
            'FRIDAY', 'FRI', 'F' => 'F',
            'SATURDAY', 'SAT', 'S' => 'S',
            'SUNDAY', 'SUN', 'SU', 'U' => 'SU',
            default => '',
        };
    }

    private function normalizeScheduleTimeToken(int $hour, int $minute, ?string $meridiem): ?string
    {
        if ($hour < 0 || $hour > 24 || $minute < 0 || $minute > 59) {
            return null;
        }

        $meridiem = strtoupper(trim((string) $meridiem));
        $meridiem = str_replace('.', '', $meridiem);
        if ($meridiem === 'AM' || $meridiem === 'PM') {
            $hour = $hour % 12;
            if ($meridiem === 'PM') {
                $hour += 12;
            }
        }

        return sprintf('%02d%02d', $hour, $minute);
    }

    private function normalizeScheduleValue(?string $value): string
    {
        $raw = strtoupper(trim((string) $value));
        if ($raw === '') {
            return '';
        }

        $raw = str_replace(["\xE2\x80\x93", "\xE2\x80\x94"], '-', $raw);
        $raw = str_replace(['&', '/', '\\'], '-', $raw);
        $raw = str_replace('TTH', 'T-TH', $raw);
        $raw = str_replace('THF', 'TH-F', $raw);
        $raw = str_replace('MWF', 'M-W-F', $raw);
        $raw = (string) preg_replace('/\s*-\s*/u', '-', $raw);
        $raw = (string) preg_replace('/\s+/u', ' ', $raw);

        $daySource = $raw;
        if (preg_match('/^\s*([A-Z\s\-]+?)(?=\d)/u', $raw, $mDay)) {
            $daySource = (string) $mDay[1];
        }

        $compactDay = (string) preg_replace('/[^A-Z]/u', '', $daySource);
        $dayTokens = [];
        if ($compactDay === 'MWF') {
            $dayTokens = ['M', 'W', 'F'];
        } elseif ($compactDay === 'TTH') {
            $dayTokens = ['T', 'TH'];
        } elseif ($compactDay === 'THF') {
            $dayTokens = ['TH', 'F'];
        } else {
            $dayParts = array_filter(array_map('trim', explode('-', (string) preg_replace('/\s+/u', '-', trim($daySource)))));
            foreach ($dayParts as $part) {
                $token = $this->normalizeWeekdayToken((string) $part);
                if ($token !== '') {
                    $dayTokens[] = $token;
                }
            }
        }

        $dayToken = implode('', $dayTokens);

        preg_match_all('/\b(\d{1,2})(?:\s*[:\.]\s*(\d{2}))?\s*((?:A\.?M\.?|P\.?M\.?|AM|PM))?\b/u', $raw, $matches, PREG_SET_ORDER);
        $times = [];
        foreach ($matches as $match) {
            $hour = (int) ($match[1] ?? -1);
            $minute = (int) (($match[2] ?? '') !== '' ? $match[2] : 0);
            $meridiem = isset($match[3]) ? (string) $match[3] : '';
            $meridiem = strtoupper(str_replace('.', '', trim($meridiem)));

            if ($hour > 24 || $minute > 59) {
                continue;
            }

            $times[] = [
                'hour' => $hour,
                'minute' => $minute,
                'meridiem' => strtoupper(trim($meridiem)),
            ];
        }

        if (count($times) >= 2) {
            $inferredMeridiem = '';
            foreach ($times as $t) {
                if (($t['meridiem'] ?? '') !== '') {
                    $inferredMeridiem = (string) $t['meridiem'];
                }
            }

            $first = $times[0];
            $second = $times[1];
            $start = $this->normalizeScheduleTimeToken(
                (int) ($first['hour'] ?? -1),
                (int) ($first['minute'] ?? -1),
                (string) (($first['meridiem'] ?? '') !== '' ? $first['meridiem'] : $inferredMeridiem)
            );
            $end = $this->normalizeScheduleTimeToken(
                (int) ($second['hour'] ?? -1),
                (int) ($second['minute'] ?? -1),
                (string) (($second['meridiem'] ?? '') !== '' ? $second['meridiem'] : $inferredMeridiem)
            );

            if ($start !== null && $end !== null) {
                return $dayToken . '|' . $start . '-' . $end;
            }
        }

        return $dayToken . '|' . (string) preg_replace('/[^A-Z0-9]/u', '', $raw);
    }

    private function parseNormalizedScheduleToken(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['day' => '', 'time' => '', 'raw' => ''];
        }

        if (preg_match('/^([A-Z]*)\|([0-9]{4}-[0-9]{4})$/u', $value, $m)) {
            return [
                'day' => (string) ($m[1] ?? ''),
                'time' => (string) ($m[2] ?? ''),
                'raw' => $value,
            ];
        }

        return ['day' => '', 'time' => '', 'raw' => $value];
    }

    private function splitNormalizedDayTokens(string $value): array
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return [];
        }

        $tokens = [];
        $i = 0;
        $len = strlen($value);
        while ($i < $len) {
            if (substr($value, $i, 2) === 'TH') {
                $tokens['TH'] = true;
                $i += 2;
                continue;
            }

            if (substr($value, $i, 2) === 'SU') {
                $tokens['SU'] = true;
                $i += 2;
                continue;
            }

            $char = $value[$i];
            if (preg_match('/[A-Z]/', $char)) {
                $tokens[$char] = true;
            }
            $i++;
        }

        return array_keys($tokens);
    }

    private function scheduleDaysOverlap(string $targetDays, string $rowDays): bool
    {
        if ($targetDays === '' || $rowDays === '') {
            return true;
        }

        $targetTokens = $this->splitNormalizedDayTokens($targetDays);
        $rowTokens = $this->splitNormalizedDayTokens($rowDays);
        if (empty($targetTokens) || empty($rowTokens)) {
            return true;
        }

        return !empty(array_intersect($targetTokens, $rowTokens));
    }

    private function subjectCodesAreCompatible(string $targetCode, string $rowCode): bool
    {
        $target = $this->normalizeSubjectCode($targetCode);
        $row = $this->normalizeSubjectCode($rowCode);
        if ($target === '' || $row === '') {
            return false;
        }

        if ($target === $row) {
            return true;
        }

        $targetCompact = $this->compactSubjectCode($target);
        $rowCompact = $this->compactSubjectCode($row);
        if ($targetCompact === '' || $rowCompact === '') {
            return false;
        }

        if ($targetCompact === $rowCompact) {
            return true;
        }

        $targetTail = $this->extractSubjectCodeNumericTail($targetCompact);
        $rowTail = $this->extractSubjectCodeNumericTail($rowCompact);
        if ($targetTail === '' || $rowTail === '' || $targetTail !== $rowTail) {
            return false;
        }

        $distance = levenshtein($targetCompact, $rowCompact);
        return $distance <= 2;
    }

    private function codeListContainsCompatibleSubject(array $codes, string $targetCode): bool
    {
        foreach ($codes as $code) {
            if ($this->subjectCodesAreCompatible($targetCode, (string) $code)) {
                return true;
            }
        }

        return false;
    }

    private function schedulesAreCompatible(string $targetSchedule, string $rowSchedule): bool
    {
        if ($targetSchedule === '' || $rowSchedule === '') {
            return true;
        }

        if ($targetSchedule === $rowSchedule) {
            return true;
        }

        $target = $this->parseNormalizedScheduleToken($targetSchedule);
        $row = $this->parseNormalizedScheduleToken($rowSchedule);

        if ($target['time'] !== '' && $row['time'] !== '' && $target['time'] === $row['time']) {
            if ($this->scheduleDaysOverlap((string) $target['day'], (string) $row['day'])) {
                return true;
            }
        }

        return false;
    }

    private function normalizeDescriptionForMatch(?string $value, string $rowCode = ''): string
    {
        $normalized = $this->normalizeLoadslipDescription((string) $value, $rowCode);
        if ($normalized === '') {
            return '';
        }

        $normalized = strtoupper($normalized);
        $normalized = (string) preg_replace('/[^A-Z0-9\s]/u', ' ', $normalized);
        $normalized = (string) preg_replace('/\s+/u', ' ', $normalized);

        return trim($normalized);
    }

    private function descriptionsAreCompatible(string $targetDescription, string $rowDescription): bool
    {
        if ($targetDescription === '' || $rowDescription === '') {
            return true;
        }

        if ($targetDescription === $rowDescription) {
            return true;
        }

        if (str_contains($targetDescription, $rowDescription) || str_contains($rowDescription, $targetDescription)) {
            return true;
        }

        $tokenize = static function (string $value): array {
            $parts = preg_split('/\s+/u', $value) ?: [];
            $tokens = [];
            foreach ($parts as $part) {
                $token = trim((string) $part);
                if ($token === '' || strlen($token) < 4) {
                    continue;
                }
                if (in_array($token, ['WITH', 'FROM', 'THIS', 'THAT', 'THEORY'], true)) {
                    continue;
                }
                $tokens[$token] = true;
            }

            return array_keys($tokens);
        };

        $targetTokens = $tokenize($targetDescription);
        $rowTokens = $tokenize($rowDescription);
        if (empty($targetTokens) || empty($rowTokens)) {
            return true;
        }

        if (!empty(array_intersect($targetTokens, $rowTokens))) {
            return true;
        }

        similar_text($targetDescription, $rowDescription, $percent);
        return $percent >= 55.0;
    }

    private function qrLoadslipRowMatches(
        Request $request,
        string $schoolId,
        string $subjectCode,
        ?string $section,
        ?string $schedule,
        ?string $description = null
    ): bool {
        if (!$this->isLoadslipVerifiedForStudent($request, $schoolId)) {
            return false;
        }

        $targetCode = $this->normalizeSubjectCode($subjectCode);
        $targetSection = $this->normalizeSectionValue($section);
        $targetSchedule = $this->normalizeScheduleValue($schedule);
        $targetDescription = $this->normalizeDescriptionForMatch($description, $targetCode);

        $matchesRows = function (array $rows) use ($targetCode, $targetSection, $targetSchedule, $targetDescription): bool {
            if (empty($rows)) {
                return false;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $rowCode = $this->normalizeSubjectCode((string) ($row['code'] ?? ''));
                if (!$this->subjectCodesAreCompatible($targetCode, $rowCode)) {
                    continue;
                }

                $rowSection = $this->normalizeSectionValue((string) ($row['section'] ?? ''));
                // OCR artifact guard: sometimes section is parsed as the subject numeric suffix (e.g., ITS 404 -> section "404").
                if ($rowSection !== '' && preg_match('/^(?:[A-Z]{2,}\s)?(\d{1,4}[A-Z]?)$/u', $rowCode, $codeSuffixMatch)) {
                    $codeSuffix = strtoupper((string) ($codeSuffixMatch[1] ?? ''));
                    if ($codeSuffix !== '' && strtoupper($rowSection) === $codeSuffix) {
                        $rowSection = '';
                    }
                }
                $rowSchedule = $this->normalizeScheduleValue((string) ($row['schedule'] ?? ''));

                // Empty parsed section means OCR missed it; allow as wildcard.
                if ($targetSection !== '' && $rowSection !== '' && $rowSection !== $targetSection) {
                    continue;
                }

                if (!$this->schedulesAreCompatible($targetSchedule, $rowSchedule)) {
                    continue;
                }

                $rowDescription = $this->normalizeDescriptionForMatch((string) ($row['description'] ?? ''), $rowCode);
                if (!$this->descriptionsAreCompatible($targetDescription, $rowDescription)) {
                    continue;
                }

                return true;
            }

            return false;
        };

        $rowsContainTargetCode = function (array $rows) use ($targetCode): bool {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowCode = $this->normalizeSubjectCode((string) ($row['code'] ?? ''));
                if ($this->subjectCodesAreCompatible($targetCode, $rowCode)) {
                    return true;
                }
            }
            return false;
        };

        $normalizeCodeList = fn(array $codes): array => array_values(array_unique(array_filter(array_map(
            fn($code) => $this->normalizeSubjectCode((string) $code),
            $codes
        ))));

        $session = $request->getSession();
        $rows = (array) $session->get('student_loadslip_rows', []);
        if ($matchesRows($rows)) {
            return true;
        }

        // If OCR missed section/schedule for a row, allow code-level fallback from session codes.
        $sessionCodes = $normalizeCodeList((array) $session->get('student_loadslip_codes', []));
        if (!$rowsContainTargetCode($rows) && $this->codeListContainsCompatibleSubject($sessionCodes, $targetCode)) {
            return true;
        }

        // Stale session fallback: retry using persisted verification payload.
        $persisted = $this->readLoadslipVerificationData($schoolId);
        if ($persisted !== null) {
            $persistedCodes = (array) ($persisted['codes'] ?? []);
            $persistedRows = (array) ($persisted['rows'] ?? []);
            $session->set('student_loadslip_codes', $persistedCodes);
            $session->set('student_loadslip_rows', $persistedRows);
            $session->set('student_loadslip_student_number', (string) ($persisted['studentNumber'] ?? ''));
            $session->set('student_loadslip_verified', (bool) ($persisted['verified'] ?? true));

            if ($matchesRows($persistedRows)) {
                return true;
            }

            if (!$rowsContainTargetCode($persistedRows)) {
                $normalizedPersistedCodes = $normalizeCodeList($persistedCodes);
                if ($this->codeListContainsCompatibleSubject($normalizedPersistedCodes, $targetCode)) {
                    return true;
                }
            }

            // Last-resort: if the exact subject code exists in persisted codes, don't block due OCR row-shape errors.
            $targetCodeInPersisted = $this->codeListContainsCompatibleSubject(
                array_map(fn($code) => $this->normalizeSubjectCode((string) $code), $persistedCodes),
                $targetCode
            );
            if ($targetCodeInPersisted) {
                return true;
            }
        }

        // Self-heal: re-parse the stored image preview and retry matching.
        if ($this->recoverVerifiedRowsFromStoredPreview($request, $schoolId)) {
            $recoveredRows = (array) $session->get('student_loadslip_rows', []);
            if ($matchesRows($recoveredRows)) {
                return true;
            }

            $recoveredCodes = $normalizeCodeList((array) $session->get('student_loadslip_codes', []));
            if (!$rowsContainTargetCode($recoveredRows) && $this->codeListContainsCompatibleSubject($recoveredCodes, $targetCode)) {
                return true;
            }
        }

        return false;
    }

    private function getQrLoadslipMatchedRows(
        Request $request,
        string $schoolId,
        string $subjectCode,
        ?string $section,
        ?string $schedule,
        ?string $description = null
    ): array {
        if (!$this->isLoadslipVerifiedForStudent($request, $schoolId)) {
            return [];
        }

        $targetCode = $this->normalizeSubjectCode($subjectCode);
        $targetSection = $this->normalizeSectionValue($section);
        $targetSchedule = $this->normalizeScheduleValue($schedule);
        $targetDescription = $this->normalizeDescriptionForMatch($description, $targetCode);
        $session = $request->getSession();

        $buildFallbackEntry = function (array $row) use ($targetCode): array {
            $rowCode = $this->normalizeSubjectCode((string) ($row['code'] ?? ''));
            if ($rowCode === '') {
                $rowCode = $targetCode;
            }

            return [
                'code' => $rowCode,
                'section' => trim((string) ($row['section'] ?? '')),
                'description' => $this->normalizeLoadslipDescription((string) ($row['description'] ?? ''), $rowCode),
                'schedule' => trim((string) ($row['schedule'] ?? '')),
                'units' => trim((string) ($row['units'] ?? '')),
            ];
        };

        $buildSyntheticMatch = function () use ($targetCode, $subjectCode, $section, $schedule, $description): array {
            $normalizedDescription = $this->normalizeLoadslipDescription((string) $description, $targetCode);

            return [[
                'code' => $targetCode !== '' ? $targetCode : trim((string) $subjectCode),
                'section' => trim((string) ($section ?? '')),
                'description' => $normalizedDescription !== '' ? $normalizedDescription : trim((string) ($description ?? '')),
                'schedule' => trim((string) ($schedule ?? '')),
                'units' => '',
            ]];
        };

        $collect = function (array $rows) use ($targetCode, $targetSection, $targetSchedule, $targetDescription): array {
            $matches = [];
            $seen = [];

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $rowCode = $this->normalizeSubjectCode((string) ($row['code'] ?? ''));
                if (!$this->subjectCodesAreCompatible($targetCode, $rowCode)) {
                    continue;
                }

                $rowSection = $this->normalizeSectionValue((string) ($row['section'] ?? ''));
                if ($rowSection !== '' && preg_match('/^(?:[A-Z]{2,}\s)?(\d{1,4}[A-Z]?)$/u', $rowCode, $codeSuffixMatch)) {
                    $codeSuffix = strtoupper((string) ($codeSuffixMatch[1] ?? ''));
                    if ($codeSuffix !== '' && strtoupper($rowSection) === $codeSuffix) {
                        $rowSection = '';
                    }
                }

                if ($targetSection !== '' && $rowSection !== '' && $rowSection !== $targetSection) {
                    continue;
                }

                $rowScheduleRaw = trim((string) ($row['schedule'] ?? ''));
                $rowSchedule = $this->normalizeScheduleValue($rowScheduleRaw);
                if (!$this->schedulesAreCompatible($targetSchedule, $rowSchedule)) {
                    continue;
                }

                $rowDescription = $this->normalizeDescriptionForMatch((string) ($row['description'] ?? ''), $rowCode);
                if (!$this->descriptionsAreCompatible($targetDescription, $rowDescription)) {
                    continue;
                }

                $entry = [
                    'code' => $rowCode,
                    'section' => trim((string) ($row['section'] ?? '')),
                    'description' => $this->normalizeLoadslipDescription((string) ($row['description'] ?? ''), $rowCode),
                    'schedule' => $rowScheduleRaw,
                    'units' => trim((string) ($row['units'] ?? '')),
                ];

                $key = $entry['code']
                    . '|' . $this->normalizeSectionValue($entry['section'])
                    . '|' . $this->normalizeScheduleValue($entry['schedule'])
                    . '|' . strtoupper($entry['description']);
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $matches[] = $entry;
            }

            return $matches;
        };

        $collectCodeOnly = function (array $rows) use ($targetCode, $buildFallbackEntry): array {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $rowCode = $this->normalizeSubjectCode((string) ($row['code'] ?? ''));
                if (!$this->subjectCodesAreCompatible($targetCode, $rowCode)) {
                    continue;
                }

                return [$buildFallbackEntry($row)];
            }

            return [];
        };

        $normalizeCodeList = fn(array $codes): array => array_values(array_unique(array_filter(array_map(
            fn($code) => $this->normalizeSubjectCode((string) $code),
            $codes
        ))));

        $sessionRows = (array) $session->get('student_loadslip_rows', []);
        $matched = $collect($sessionRows);
        if (!empty($matched)) {
            return $matched;
        }

        $codeOnlyMatches = $collectCodeOnly($sessionRows);
        if (!empty($codeOnlyMatches)) {
            return $codeOnlyMatches;
        }

        $sessionCodes = $normalizeCodeList((array) $session->get('student_loadslip_codes', []));
        if ($this->codeListContainsCompatibleSubject($sessionCodes, $targetCode)) {
            return $buildSyntheticMatch();
        }

        $persisted = $this->readLoadslipVerificationData($schoolId);
        if ($persisted !== null) {
            $persistedRows = (array) ($persisted['rows'] ?? []);
            $matched = $collect($persistedRows);
            if (!empty($matched)) {
                return $matched;
            }

            $codeOnlyMatches = $collectCodeOnly($persistedRows);
            if (!empty($codeOnlyMatches)) {
                return $codeOnlyMatches;
            }

            $persistedCodes = $normalizeCodeList((array) ($persisted['codes'] ?? []));
            if ($this->codeListContainsCompatibleSubject($persistedCodes, $targetCode)) {
                return $buildSyntheticMatch();
            }
        }

        if ($this->recoverVerifiedRowsFromStoredPreview($request, $schoolId)) {
            $recoveredRows = (array) $session->get('student_loadslip_rows', []);
            $matched = $collect($recoveredRows);
            if (!empty($matched)) {
                return $matched;
            }

            $codeOnlyMatches = $collectCodeOnly($recoveredRows);
            if (!empty($codeOnlyMatches)) {
                return $codeOnlyMatches;
            }

            $recoveredCodes = $normalizeCodeList((array) $session->get('student_loadslip_codes', []));
            if ($this->codeListContainsCompatibleSubject($recoveredCodes, $targetCode)) {
                return $buildSyntheticMatch();
            }
        }

        return [];
    }

    private function recoverVerifiedRowsFromStoredPreview(Request $request, string $schoolId): bool
    {
        $session = $request->getSession();
        $normalizedSchoolId = $this->normalizeStudentNumber($schoolId);
        if ($normalizedSchoolId === '') {
            return false;
        }

        $previewPath = $this->normalizeLoadslipPreviewPath((string) $session->get('student_loadslip_preview_path', ''));
        $persisted = null;
        if ($previewPath === '') {
            $persisted = $this->readLoadslipVerificationData($schoolId);
            $previewPath = $this->normalizeLoadslipPreviewPath((string) ($persisted['previewPath'] ?? ''));
        }

        if ($previewPath === '' || !$this->loadslipPreviewPathExists($previewPath)) {
            return false;
        }

        $absolutePreviewPath = $this->getAbsolutePublicPath($previewPath);
        if (!is_file($absolutePreviewPath)) {
            return false;
        }

        $ocrDebugData = null;
        $reparsedRows = [];
        $importData = $this->parseLoadslipImage($absolutePreviewPath, $ocrDebugData, $reparsedRows, $normalizedSchoolId);

        $targetStudent = $this->userRepo->findOneBy(['schoolId' => $normalizedSchoolId]);
        $curriculumCodeMap = $this->getCurriculumSubjectCodeMapForUser($targetStudent, $this->curriculumRepo);
        $knownCodeByCompact = $this->buildKnownSubjectCodeByCompact($this->subjectRepo, $curriculumCodeMap);

        $recoveryCodeTrace = [];
        $reparsedCodes = [];
        foreach ((array) ($importData['codes'] ?? []) as $code) {
            $inputCode = $this->normalizeSubjectCode((string) $code);
            $resolvedCode = $this->resolveToKnownSubjectCode((string) $code, $knownCodeByCompact);
            if ($resolvedCode === '') {
                $recoveryCodeTrace[] = [
                    'input' => $inputCode,
                    'resolved' => '',
                    'status' => 'dropped_unknown_code',
                ];
                continue;
            }

            if (!empty($curriculumCodeMap) && !isset($curriculumCodeMap[$resolvedCode])) {
                $recoveryCodeTrace[] = [
                    'input' => $inputCode,
                    'resolved' => $resolvedCode,
                    'status' => 'dropped_not_in_curriculum',
                ];
                continue;
            }

            $recoveryCodeTrace[] = [
                'input' => $inputCode,
                'resolved' => $resolvedCode,
                'status' => $resolvedCode === $inputCode ? 'exact' : 'fuzzy',
            ];
            $reparsedCodes[] = $resolvedCode;
        }
        $reparsedCodes = array_values(array_unique(array_filter($reparsedCodes)));

        if (empty($reparsedCodes) && empty($reparsedRows)) {
            return false;
        }

        $recoveryRowTrace = [];
        $normalizedRows = [];
        $rowKeys = [];
        foreach ($reparsedRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rawCode = $this->normalizeSubjectCode((string) ($row['code'] ?? ''));
            $code = $this->resolveToKnownSubjectCode($rawCode, $knownCodeByCompact);
            $code = $this->applyLoadslipSubjectCodeDescriptionHeuristic($code, (string) ($row['description'] ?? ''));
            if ($code === '' || !$this->isLikelySubjectCode($code)) {
                $recoveryRowTrace[] = [
                    'inputCode' => $rawCode,
                    'resolvedCode' => '',
                    'section' => trim((string) ($row['section'] ?? '')),
                    'schedule' => trim((string) ($row['schedule'] ?? '')),
                    'status' => 'dropped_unknown_code',
                ];
                continue;
            }

            if (!empty($curriculumCodeMap) && !isset($curriculumCodeMap[$code])) {
                $recoveryRowTrace[] = [
                    'inputCode' => $rawCode,
                    'resolvedCode' => $code,
                    'section' => trim((string) ($row['section'] ?? '')),
                    'schedule' => trim((string) ($row['schedule'] ?? '')),
                    'status' => 'dropped_not_in_curriculum',
                ];
                continue;
            }

            $section = trim((string) ($row['section'] ?? ''));
            $schedule = trim((string) ($row['schedule'] ?? ''));
            $key = $code
                . '|' . $this->normalizeSectionValue($section)
                . '|' . $this->normalizeScheduleValue($schedule);
            if (isset($rowKeys[$key])) {
                $recoveryRowTrace[] = [
                    'inputCode' => $rawCode,
                    'resolvedCode' => $code,
                    'section' => $section,
                    'schedule' => $schedule,
                    'status' => 'dropped_duplicate',
                ];
                continue;
            }

            $rowKeys[$key] = true;
            $normalizedRows[] = [
                'code' => $code,
                'section' => $section,
                'description' => $this->normalizeLoadslipDescription((string) ($row['description'] ?? ''), $code),
                'schedule' => $schedule,
                'units' => trim((string) ($row['units'] ?? '')),
            ];
            $recoveryRowTrace[] = [
                'inputCode' => $rawCode,
                'resolvedCode' => $code,
                'section' => $section,
                'schedule' => $schedule,
                'status' => $code === $rawCode ? 'exact' : 'fuzzy',
            ];
            $reparsedCodes[] = $code;
        }

        $reparsedCodes = array_values(array_unique(array_filter($reparsedCodes)));
        if (!empty($normalizedRows)) {
            $reparsedCodes = array_values(array_unique(array_filter(array_map(
                fn(array $row): string => $this->normalizeSubjectCode((string) ($row['code'] ?? '')),
                $normalizedRows
            ))));
        }
        if (empty($reparsedCodes) && empty($normalizedRows)) {
            return false;
        }

        $session->set('student_loadslip_codes', $reparsedCodes);
        $session->set('student_loadslip_rows', $normalizedRows);
        $session->set('student_loadslip_student_number', $normalizedSchoolId);
        $session->set('student_loadslip_verified', true);
        $session->set('student_loadslip_preview_path', $previewPath);

        $canStoreOcrDebug = (bool) $this->getParameter('kernel.debug') || $this->isGranted('ROLE_ADMIN');
        if ($canStoreOcrDebug && is_array($ocrDebugData)) {
            $ocrDebugData['recoveredFromPreview'] = true;
            $ocrDebugData['recoveryTargetStudent'] = $normalizedSchoolId;
            $ocrDebugData['recoveryTrace'] = [
                'curriculumCodeCount' => count($curriculumCodeMap),
                'codeResolution' => $recoveryCodeTrace,
                'rowResolution' => $recoveryRowTrace,
            ];
            $session->set('student_loadslip_ocr_debug', $ocrDebugData);
        }

        $this->persistLoadslipVerificationData($schoolId, $reparsedCodes, $normalizedRows, $normalizedSchoolId, $previewPath);
        return true;
    }

    /**
     * Extract student number from document text with robust pattern matching.
     * Handles OCR errors and various formatting variations.
     */
    private function extractStudentNumber(string $text, ?string $expectedStudentNumber = null): ?string
    {
        $text = strtoupper($text);
        $expected = $expectedStudentNumber !== null ? $this->normalizeStudentNumber($expectedStudentNumber) : '';
        $candidates = [];

        $addCandidate = function (string $rawCandidate, int $baseScore) use (&$candidates, $expected): void {
            $normalized = $this->normalizeStudentNumber($rawCandidate);
            if ($normalized === '') {
                return;
            }

            $len = strlen($normalized);
            if ($len < 6 || $len > 12) {
                return;
            }

            $score = $baseScore;
            if ($len >= 8 && $len <= 10) {
                $score += 20;
            }
            if ($expected !== '') {
                if ($normalized === $expected) {
                    $score += 1000;
                }
                $score -= abs($len - strlen($expected)) * 2;
            }

            if (!isset($candidates[$normalized]) || $score > $candidates[$normalized]) {
                $candidates[$normalized] = $score;
            }
        };

        $patternMap = [
            '/STU[D0]ENT\s*(?:NUMBER|NUM(?:BER)?|NO\.?|#|ID)\s*[:=\-]?\s*([A-Z0-9\|!\-\/\s]{6,24})/u' => 140,
            '/(?:SCHOOL\s*ID|STUDENT\s*ID|ID\s*NO\.?|ID#)\s*[:=\-]?\s*([A-Z0-9\|!\-\/\s]{6,24})/u' => 130,
            '/(?:STUDENT\s*NUMBER|STUDENT\s*NO\.?)\s*([A-Z0-9\|!\-\/\s]{6,24})/u' => 120,
            '/^([A-Z0-9\|!\-\/\s]{6,24})\s*[,\t]/mu' => 80,
        ];

        foreach ($patternMap as $pattern => $score) {
            if (!preg_match_all($pattern, $text, $matches)) {
                continue;
            }
            foreach (($matches[1] ?? []) as $candidate) {
                $addCandidate((string) $candidate, $score);
            }
        }

        $lines = preg_split('/\R/u', $text) ?: [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (!preg_match('/STUDENT|SCHOOL\s*ID|ID\s*NO\.?|ID#/u', $line)) {
                continue;
            }

            if (preg_match_all('/[A-Z0-9\|!\-\/]{6,24}/u', $line, $tokens)) {
                foreach (($tokens[0] ?? []) as $token) {
                    $addCandidate((string) $token, 90);
                }
            }
        }

        // Last-resort fallback for OCR where labels are degraded.
        if (empty($candidates) && preg_match_all('/\b[0-9][0-9\-\s]{5,14}\b/u', $text, $fallback)) {
            foreach (($fallback[0] ?? []) as $token) {
                $addCandidate((string) $token, 30);
            }
        }

        if (empty($candidates)) {
            return null;
        }

        arsort($candidates, SORT_NUMERIC);

        if ($expected !== '' && !isset($candidates[$expected])) {
            $bestScore = (int) reset($candidates);
            // Weak OCR hits (dates/times/noisy numbers) should not trigger mismatch.
            if ($bestScore < 130) {
                return null;
            }
        }

        return (string) array_key_first($candidates);
    }

    private function resolveTesseractExecutable(): ?string
    {
        $envPath = trim((string) ($_ENV['TESSERACT_PATH'] ?? $_SERVER['TESSERACT_PATH'] ?? ''));
        if ($envPath !== '' && is_file($envPath)) {
            return $envPath;
        }

        $candidates = [
            'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
            'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Enhance loadslip image before OCR: upscale, grayscale, and contrast boost.
     *
     * @return string|null Temporary enhanced image path (PNG) or null when enhancement is unavailable.
     */
    private function createEnhancedLoadslipImageForOcr(string $path, ?array &$meta = null): ?string
    {
        $resolutionProfile = 'high_trace';
        $requestedScale = 2.4;
        $maxDimension = 3200.0;
        $maxPixels = 9500000.0;

        $meta = [
            'applied' => false,
            'scale' => 1,
            'adaptiveScale' => 1,
            'requestedScale' => $requestedScale,
            'source' => 'original',
            'profile' => $resolutionProfile,
            'maxDimension' => (int) $maxDimension,
            'maxPixels' => (int) $maxPixels,
        ];

        if (!extension_loaded('gd') || !is_file($path)) {
            return null;
        }

        $imageInfo = @getimagesize($path);
        if (!is_array($imageInfo)) {
            return null;
        }

        $imageType = (int) ($imageInfo[2] ?? 0);
        if (!in_array($imageType, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return null;
        }

        $width = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        $scale = $requestedScale;
        // Guardrails to avoid oversized intermediate images on high-resolution imports.

        $largestSide = (float) max($width, $height);
        if ($largestSide > 0) {
            $scale = min($scale, $maxDimension / $largestSide);
        }

        $pixelCount = (float) $width * (float) $height;
        if ($pixelCount > 0) {
            $scale = min($scale, sqrt($maxPixels / $pixelCount));
        }

        if ($scale <= 0) {
            return null;
        }

        $scale = max(0.35, $scale);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $toBytes = static function (string $value): int {
            $trimmed = trim($value);
            if ($trimmed === '' || $trimmed === '-1') {
                return -1;
            }

            $unit = strtolower(substr($trimmed, -1));
            $number = (float) $trimmed;
            if (!is_finite($number) || $number <= 0) {
                return -1;
            }

            return match ($unit) {
                'g' => (int) round($number * 1024 * 1024 * 1024),
                'm' => (int) round($number * 1024 * 1024),
                'k' => (int) round($number * 1024),
                default => (int) round($number),
            };
        };

        $memoryLimit = $toBytes((string) ini_get('memory_limit'));
        if ($memoryLimit > 0) {
            $available = $memoryLimit - memory_get_usage(true);
            // Rough GD allocation estimate with safety margin (source + target + overhead).
            $estimatedNeeded = (int) ceil(($pixelCount * 5.5) + ((float) $targetWidth * (float) $targetHeight * 5.5) + (24 * 1024 * 1024));
            if ($available <= 0 || $estimatedNeeded > (int) floor($available * 0.82)) {
                $meta = [
                    'applied' => false,
                    'scale' => 1,
                    'adaptiveScale' => round($scale, 3),
                    'requestedScale' => $requestedScale,
                    'source' => 'original',
                    'profile' => $resolutionProfile,
                    'reason' => 'memory_guard',
                    'maxDimension' => (int) $maxDimension,
                    'maxPixels' => (int) $maxPixels,
                    'originalWidth' => $width,
                    'originalHeight' => $height,
                    'targetWidth' => $targetWidth,
                    'targetHeight' => $targetHeight,
                ];
                return null;
            }
        }

        $source = $imageType === IMAGETYPE_JPEG
            ? @imagecreatefromjpeg($path)
            : @imagecreatefrompng($path);

        if ($source === false) {
            return null;
        }

        $enhanced = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($enhanced === false) {
            imagedestroy($source);
            return null;
        }

        if ($imageType === IMAGETYPE_PNG) {
            imagealphablending($enhanced, false);
            imagesavealpha($enhanced, true);
            $transparent = imagecolorallocatealpha($enhanced, 0, 0, 0, 127);
            imagefill($enhanced, 0, 0, $transparent);
        }

        imagecopyresampled($enhanced, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        // OCR-friendly pass aligned with browser-canvas preprocessing.
        imagefilter($enhanced, IMG_FILTER_GRAYSCALE);
        imagefilter($enhanced, IMG_FILTER_CONTRAST, -25);

        $tempBase = tempnam(sys_get_temp_dir(), 'ocr_enh_');
        if ($tempBase === false) {
            imagedestroy($enhanced);
            imagedestroy($source);
            return null;
        }

        $tempPath = $tempBase . '.png';
        @unlink($tempBase);

        $saved = @imagepng($enhanced, $tempPath, 0);

        imagedestroy($enhanced);
        imagedestroy($source);

        if (!$saved || !is_file($tempPath)) {
            @unlink($tempPath);
            return null;
        }

        $meta = [
            'applied' => true,
            'scale' => round($scale, 3),
            'adaptiveScale' => round($scale, 3),
            'requestedScale' => $requestedScale,
            'source' => 'gd_upscale_grayscale_contrast',
            'profile' => $resolutionProfile,
            'maxDimension' => (int) $maxDimension,
            'maxPixels' => (int) $maxPixels,
            'originalWidth' => $width,
            'originalHeight' => $height,
            'processedWidth' => $targetWidth,
            'processedHeight' => $targetHeight,
        ];

        return $tempPath;
    }

    /**
     * Estimate image blur using Laplacian variance on a grayscale downsample.
     * Lower variance indicates a blurrier image.
     *
        * @return array{score: float, threshold: float, isBlurry: bool, contrastScore: float, contrastThreshold: float, isLowContrast: bool, isReadable: bool, sampledWidth: int, sampledHeight: int}|null
     */
    private function detectLoadslipBlur(string $path, ?array &$meta = null): ?array
    {
        if (!extension_loaded('gd') || !is_file($path)) {
            return null;
        }

        $imageInfo = @getimagesize($path);
        if (!is_array($imageInfo)) {
            return null;
        }

        $imageType = (int) ($imageInfo[2] ?? 0);
        if (!in_array($imageType, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return null;
        }

        $source = $imageType === IMAGETYPE_JPEG
            ? @imagecreatefromjpeg($path)
            : @imagecreatefrompng($path);

        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($source);
            return null;
        }

        $targetMaxSide = 420;
        $scale = min(1.0, $targetMaxSide / max($width, $height));
        $sampledWidth = max(3, (int) round($width * $scale));
        $sampledHeight = max(3, (int) round($height * $scale));

        $sample = imagecreatetruecolor($sampledWidth, $sampledHeight);
        if ($sample === false) {
            imagedestroy($source);
            return null;
        }

        imagecopyresampled($sample, $source, 0, 0, 0, 0, $sampledWidth, $sampledHeight, $width, $height);
        imagedestroy($source);

        imagefilter($sample, IMG_FILTER_GRAYSCALE);

        $gray = [];
        $graySum = 0.0;
        $graySquareSum = 0.0;
        for ($y = 0; $y < $sampledHeight; $y++) {
            $row = [];
            for ($x = 0; $x < $sampledWidth; $x++) {
                $rgb = imagecolorat($sample, $x, $y);
                $intensity = (float) (($rgb >> 16) & 0xFF);
                $row[] = $intensity;
                $graySum += $intensity;
                $graySquareSum += $intensity * $intensity;
            }
            $gray[] = $row;
        }

        imagedestroy($sample);

        $laplacianValues = [];
        for ($y = 1; $y < $sampledHeight - 1; $y++) {
            for ($x = 1; $x < $sampledWidth - 1; $x++) {
                $center = $gray[$y][$x];
                $value =
                    (-1 * $gray[$y - 1][$x])
                    + (-1 * $gray[$y][$x - 1])
                    + (4 * $center)
                    + (-1 * $gray[$y][$x + 1])
                    + (-1 * $gray[$y + 1][$x]);
                $laplacianValues[] = (float) $value;
            }
        }

        if (empty($laplacianValues)) {
            return null;
        }

        $count = count($laplacianValues);
        $sum = array_sum($laplacianValues);
        $mean = $sum / $count;
        $varianceSum = 0.0;
        foreach ($laplacianValues as $value) {
            $delta = $value - $mean;
            $varianceSum += $delta * $delta;
        }
        $variance = $varianceSum / $count;

        // Threshold tuned for document scans: values below this are usually too blurry for OCR table parsing.
        $blurThreshold = 42.0;
        $isBlurry = $variance < $blurThreshold;

        $grayCount = max(1, $sampledWidth * $sampledHeight);
        $grayMean = $graySum / $grayCount;
        $grayVariance = max(0.0, ($graySquareSum / $grayCount) - ($grayMean * $grayMean));
        $contrastScore = sqrt($grayVariance);
        // Low contrast scans (washed out or too dark) degrade OCR row extraction.
        $contrastThreshold = 18.0;
        $isLowContrast = $contrastScore < $contrastThreshold;
        $isReadable = !$isBlurry && !$isLowContrast;

        $meta = [
            'score' => round($variance, 3),
            'threshold' => $blurThreshold,
            'isBlurry' => $isBlurry,
            'contrastScore' => round($contrastScore, 3),
            'contrastThreshold' => $contrastThreshold,
            'isLowContrast' => $isLowContrast,
            'isReadable' => $isReadable,
            'sampledWidth' => $sampledWidth,
            'sampledHeight' => $sampledHeight,
        ];

        return $meta;
    }

    /**
     * Estimate whether the upload keeps the colored loadslip appearance shown in instruction samples.
     *
     * @return array{colorfulness: float, threshold: float, grayRatio: float, isColoredEnough: bool, sampledWidth: int, sampledHeight: int}|null
     */
    private function detectLoadslipColorProfile(string $path, ?array &$meta = null): ?array
    {
        if (!extension_loaded('gd') || !is_file($path)) {
            return null;
        }

        $imageInfo = @getimagesize($path);
        if (!is_array($imageInfo)) {
            return null;
        }

        $imageType = (int) ($imageInfo[2] ?? 0);
        if (!in_array($imageType, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return null;
        }

        $source = $imageType === IMAGETYPE_JPEG
            ? @imagecreatefromjpeg($path)
            : @imagecreatefrompng($path);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($source);
            return null;
        }

        $targetMaxSide = 420;
        $scale = min(1.0, $targetMaxSide / max($width, $height));
        $sampledWidth = max(2, (int) round($width * $scale));
        $sampledHeight = max(2, (int) round($height * $scale));

        $sample = imagecreatetruecolor($sampledWidth, $sampledHeight);
        if ($sample === false) {
            imagedestroy($source);
            return null;
        }

        imagecopyresampled($sample, $source, 0, 0, 0, 0, $sampledWidth, $sampledHeight, $width, $height);
        imagedestroy($source);

        $count = max(1, $sampledWidth * $sampledHeight);
        $rgSum = 0.0;
        $rgSqSum = 0.0;
        $ybSum = 0.0;
        $ybSqSum = 0.0;
        $grayLikeCount = 0;
        $graySpreadThreshold = 10;

        for ($y = 0; $y < $sampledHeight; $y++) {
            for ($x = 0; $x < $sampledWidth; $x++) {
                $rgb = imagecolorat($sample, $x, $y);
                $r = (float) (($rgb >> 16) & 0xFF);
                $g = (float) (($rgb >> 8) & 0xFF);
                $b = (float) ($rgb & 0xFF);

                $rg = $r - $g;
                $yb = (0.5 * ($r + $g)) - $b;

                $rgSum += $rg;
                $rgSqSum += $rg * $rg;
                $ybSum += $yb;
                $ybSqSum += $yb * $yb;

                $maxChannel = max($r, $g, $b);
                $minChannel = min($r, $g, $b);
                if (($maxChannel - $minChannel) <= $graySpreadThreshold) {
                    $grayLikeCount++;
                }
            }
        }

        imagedestroy($sample);

        $rgMean = $rgSum / $count;
        $ybMean = $ybSum / $count;
        $rgStd = sqrt(max(0.0, ($rgSqSum / $count) - ($rgMean * $rgMean)));
        $ybStd = sqrt(max(0.0, ($ybSqSum / $count) - ($ybMean * $ybMean)));

        // Hasler-Suesstrunk colorfulness metric.
        $colorfulness = sqrt(($rgStd * $rgStd) + ($ybStd * $ybStd))
            + (0.3 * sqrt(($rgMean * $rgMean) + ($ybMean * $ybMean)));
        $grayRatio = $grayLikeCount / $count;

        // Calibrated against current instruction samples: grayscale ~0.0, colored samples ~16+.
        $colorfulnessThreshold = 8.5;
        $isColoredEnough = $colorfulness >= $colorfulnessThreshold;

        $meta = [
            'colorfulness' => round($colorfulness, 3),
            'threshold' => $colorfulnessThreshold,
            'grayRatio' => round($grayRatio, 4),
            'isColoredEnough' => $isColoredEnough,
            'sampledWidth' => $sampledWidth,
            'sampledHeight' => $sampledHeight,
        ];

        return $meta;
    }

    private function removeStoredLoadslipPreview(Request $request): void
    {
        $current = $this->normalizeLoadslipPreviewPath((string) $request->getSession()->get('student_loadslip_preview_path', ''));
        $this->removeLoadslipPreviewPath($current);
    }

    private function removeLoadslipPreviewPath(?string $relativePath): void
    {
        $current = $this->normalizeLoadslipPreviewPath((string) $relativePath);
        if ($current === '') {
            return;
        }

        $absolute = $this->getAbsolutePublicPath($current);
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function clearStudentLoadslipState(Request $request, ?string $schoolId = null, ?string $previewPath = null): void
    {
        if ($previewPath !== null && trim($previewPath) !== '') {
            $this->removeLoadslipPreviewPath($previewPath);
        } else {
            $this->removeStoredLoadslipPreview($request);
        }

        $session = $request->getSession();
        $session->remove('student_loadslip_codes');
        $session->remove('student_loadslip_rows');
        $session->remove('student_loadslip_student_number');
        $session->remove('student_loadslip_verified');
        $session->remove('student_loadslip_preview_path');
        $session->remove('student_loadslip_ocr_debug');

        if ($schoolId !== null && trim($schoolId) !== '') {
            $this->clearLoadslipVerificationData($schoolId);
        }
    }

    private function persistLoadslipPreviewImage(Request $request, object $file, string $ext): ?string
    {
        $projectDir = rtrim((string) $this->getParameter('kernel.project_dir'), '\\/');
        $relativeDir = 'uploads/loadslip-previews';
        $absoluteDir = $projectDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
        if (!is_dir($absoluteDir)) {
            @mkdir($absoluteDir, 0775, true);
        }

        if (!method_exists($file, 'getPathname')) {
            return null;
        }

        $source = (string) $file->getPathname();
        if ($source === '' || !is_file($source)) {
            return null;
        }

        $filename = 'loadslip_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = $absoluteDir . DIRECTORY_SEPARATOR . $filename;

        if (!@copy($source, $target)) {
            return null;
        }

        $this->removeStoredLoadslipPreview($request);

        return $this->normalizeLoadslipPreviewPath($relativeDir . '/' . $filename);
    }
    
    private function persistLoadslipVerificationData(string $schoolId, array $codes, array $rows, string $studentNumber, ?string $previewPath = null, ?string $schoolYear = null, ?string $semester = null): void
    {
        $normalizedSchoolId = $this->normalizeStudentNumber($schoolId);
        $normalizedStudentNumber = $this->normalizeStudentNumber($studentNumber);
        if ($normalizedSchoolId === '' || $normalizedStudentNumber === '') {
            return;
        }

        $sanitizedCodes = array_values(array_filter(array_map(
            fn($code) => $this->normalizeSubjectCode((string) $code),
            $codes
        )));

        $sanitizedRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sanitizedRows[] = [
                'code' => $this->normalizeSubjectCode((string) ($row['code'] ?? '')),
                'section' => trim((string) ($row['section'] ?? '')),
                'description' => $this->normalizeLoadslipDescription(
                    (string) ($row['description'] ?? ''),
                    (string) ($row['code'] ?? '')
                ),
                'schedule' => trim((string) ($row['schedule'] ?? '')),
                'units' => trim((string) ($row['units'] ?? '')),
            ];
        }

        $normalizedSchoolYear = $this->normalizeSchoolYearLabel($schoolYear);
        $normalizedSemester = $this->normalizeSemesterLabel($semester);
        if ($normalizedSchoolYear === '' || $normalizedSemester === '') {
            $existing = $this->readLoadslipVerificationData($schoolId);
            if ($normalizedSchoolYear === '') {
                $normalizedSchoolYear = $this->normalizeSchoolYearLabel((string) ($existing['schoolYear'] ?? ''));
            }
            if ($normalizedSemester === '') {
                $normalizedSemester = $this->normalizeSemesterLabel((string) ($existing['semester'] ?? ''));
            }
        }

        $verification = $this->loadslipVerificationRepo->findOneBySchoolId($normalizedSchoolId) ?? new LoadslipVerification();
        $payload = [
            'studentNumber' => $normalizedStudentNumber,
            'codes' => $sanitizedCodes,
            'rows' => $sanitizedRows,
            'previewPath' => $this->normalizeLoadslipPreviewPath($previewPath),
            'schoolYear' => $normalizedSchoolYear,
            'semester' => $normalizedSemester,
            'verified' => true,
            'updatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        $verification
            ->setSchoolId($normalizedSchoolId)
            ->setStudentNumber($payload['studentNumber'])
            ->setCodes($payload['codes'])
            ->setRows($payload['rows'])
            ->setPreviewPath($payload['previewPath'] !== '' ? $payload['previewPath'] : null)
            ->setSchoolYear($payload['schoolYear'] !== '' ? $payload['schoolYear'] : null)
            ->setSemester($payload['semester'] !== '' ? $payload['semester'] : null)
            ->setVerified((bool) $payload['verified'])
            ->setUpdatedAt(new \DateTimeImmutable($payload['updatedAt']));

        $this->loadslipVerificationRepo->save($verification, true);
    }

    private function readLoadslipVerificationData(string $schoolId): ?array
    {
        $data = $this->loadslipVerificationRepo->findPayloadBySchoolId($schoolId);
        if (!is_array($data)) {
            return null;
        }

        $normalizedSchoolId = $this->normalizeStudentNumber($schoolId);
        $studentNumber = $this->normalizeStudentNumber((string) ($data['studentNumber'] ?? ''));
        if ($normalizedSchoolId === '' || $studentNumber === '' || $studentNumber !== $normalizedSchoolId) {
            return null;
        }

        $codes = is_array($data['codes'] ?? null) ? $data['codes'] : [];
        $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];

        if (empty($codes) && empty($rows)) {
            return null;
        }

        $previewPath = $this->normalizeLoadslipPreviewPath((string) ($data['previewPath'] ?? ''));
        if ($previewPath !== '' && !$this->loadslipPreviewPathExists($previewPath)) {
            $previewPath = '';
        }

        return [
            'studentNumber' => $studentNumber,
            'codes' => $codes,
            'rows' => $rows,
            'previewPath' => $previewPath,
            'schoolYear' => $this->normalizeSchoolYearLabel((string) ($data['schoolYear'] ?? '')),
            'semester' => $this->normalizeSemesterLabel((string) ($data['semester'] ?? '')),
            'verified' => (bool) ($data['verified'] ?? true),
        ];
    }

    private function clearLoadslipVerificationData(string $schoolId): void
    {
        $this->loadslipVerificationRepo->deleteBySchoolId($schoolId);
    }

    /**
     * Parse CSV/TXT loadslip. Returns array with keys: codes (array), studentNumber (?string).
     */
    private function parseLoadslipCsv(string $path, ?array &$rows = null, ?string $expectedStudentNumber = null): array
    {
        $codes = [];
        $schedulePattern = $this->getLoadslipSchedulePattern();

        $parsedRows = [];
        $studentNumber = null;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['codes' => [], 'studentNumber' => null];
        }

        $fileContent = '';
        while (($line = fgets($handle)) !== false) {
            $fileContent .= $line;
        }
        fclose($handle);

        // Extract student number from entire file content
        $studentNumber = $this->extractStudentNumber($fileContent, $expectedStudentNumber);

        // Parse CSV rows
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['codes' => [], 'studentNumber' => $studentNumber];
        }

        $header = fgetcsv($handle);
        $codeIndex = 0;
        $sectionIndex = null;
        $descriptionIndex = null;
        $scheduleIndex = null;
        $unitIndex = null;
        $hasStructuredHeader = false;

        if (is_array($header)) {
            $normalizedHeader = array_map(fn($v) => $this->normalizeHeaderName((string) $v), $header);

            $findIndex = static function (array $headerValues, array $candidates): ?int {
                foreach ($candidates as $candidate) {
                    $idx = array_search($candidate, $headerValues, true);
                    if ($idx !== false) {
                        return (int) $idx;
                    }
                }
                return null;
            };

            $codeIndex = $findIndex($normalizedHeader, ['subject', 'subject code', 'subject_code', 'code']) ?? 0;
            $sectionIndex = $findIndex($normalizedHeader, ['section', 'sec', 'block', 'class']);
            $descriptionIndex = $findIndex($normalizedHeader, ['description', 'subject description']);
            $scheduleIndex = $findIndex($normalizedHeader, ['schedule', 'time', 'day/time', 'days/time', 'day and time']);
            $unitIndex = $findIndex($normalizedHeader, ['units', 'unit']);

            // Section + schedule are required for QR matching; description/units are optional.
            $hasStructuredHeader = $sectionIndex !== null && $scheduleIndex !== null;

            // If the first row is not a header, treat it as data.
            if ($findIndex($normalizedHeader, ['subject', 'subject code', 'subject_code', 'code']) === null) {
                $raw = trim((string) ($header[0] ?? ''));
                if ($raw !== '') {
                    $normalizedCode = $this->normalizeSubjectCode($raw);
                    $codes[$normalizedCode] = true;
                    $parsedRows[] = [
                        'code' => $normalizedCode,
                        'section' => '',
                        'description' => '',
                        'schedule' => '',
                        'units' => '',
                    ];
                }
            }
        }

        while (($row = fgetcsv($handle)) !== false) {
            // Extract student number from data rows as fallback.
            if ($studentNumber === null) {
                $studentNumber = $this->extractStudentNumber(implode(' ', array_map(static fn($cell) => (string) $cell, $row)), $expectedStudentNumber);
            }

            $rowText = strtoupper(implode(' ', array_map(static fn($cell) => (string) $cell, $row)));
            $raw = trim((string) ($row[$codeIndex] ?? ''));
            if ($raw === '' && !$hasStructuredHeader) {
                if (preg_match('/\b([A-Z]{2,8}\s*-?\s*[0-9OQDILSZGB]{1,4}[A-Z]?)\b/u', $rowText, $codeMatch)) {
                    $raw = trim((string) ($codeMatch[1] ?? ''));
                } elseif (preg_match('/\b([A-Z0-9]{2,8}\s*-?\s*[A-Z0-9]{1,6}|[A-Z0-9]{4,12})\b/u', $rowText, $codeMatch)) {
                    $raw = trim((string) ($codeMatch[1] ?? ''));
                }
            }
            if ($raw === '') {
                continue;
            }

            if ($hasStructuredHeader) {
                $section = trim((string) ($sectionIndex !== null ? ($row[$sectionIndex] ?? '') : ''));
                $description = trim((string) ($descriptionIndex !== null ? ($row[$descriptionIndex] ?? '') : ''));
                $schedule = trim((string) ($scheduleIndex !== null ? ($row[$scheduleIndex] ?? '') : ''));
                $unit = trim((string) ($unitIndex !== null ? ($row[$unitIndex] ?? '') : ''));

                if ($section === '' || $schedule === '') {
                    if ($section === '' && preg_match('/\bSEC(?:TION)?\s*[:\-]?\s*([A-Z0-9\-]{1,6})\b/u', $rowText, $secMatch)) {
                        $section = $this->normalizeSectionTokenFromOcr((string) ($secMatch[1] ?? ''), $raw);
                    }
                    if ($schedule === '') {
                        $schedule = $this->extractScheduleFromText($rowText, $schedulePattern);
                    }
                    if ($section === '' && preg_match('/^\s*' . preg_quote($raw, '/') . '\s+([A-Z0-9\-]{1,6})\b/u', $rowText, $secAfterCode)) {
                        $secCandidate = $this->normalizeSectionTokenFromOcr((string) ($secAfterCode[1] ?? ''), $raw);
                        if ($this->isLikelySectionToken($secCandidate, $raw)) {
                            $section = $secCandidate;
                        }
                    }
                }

                if (!$this->isLikelySubjectCode($raw)) {
                    continue;
                }

                // Keep rows that have schedule; section may be blank when OCR/CSV shape is noisy.
                if ($schedule === '') {
                    continue;
                }

                $normalizedCode = $this->normalizeSubjectCode($raw);
                $section = $this->normalizeSectionTokenFromOcr($section, $normalizedCode);
                $description = $this->normalizeLoadslipDescription($description, $normalizedCode);
                $codes[$normalizedCode] = true;
                $parsedRows[] = [
                    'code' => $normalizedCode,
                    'section' => $section,
                    'description' => $description,
                    'schedule' => $schedule,
                    'units' => $unit,
                ];
                continue;
            }

            $normalizedCode = $this->normalizeSubjectCode($raw);
            if ($normalizedCode === '' || !$this->isLikelySubjectCode($normalizedCode)) {
                continue;
            }

            $schedule = $this->extractScheduleFromText($rowText, $schedulePattern);
            $section = '';
            if (preg_match('/\bSEC(?:TION)?\s*[:\-]?\s*([A-Z0-9\-]{1,6})\b/u', $rowText, $secMatch)) {
                $secCandidate = $this->normalizeSectionTokenFromOcr((string) ($secMatch[1] ?? ''), $normalizedCode);
                if ($this->isLikelySectionToken($secCandidate, $normalizedCode)) {
                    $section = $secCandidate;
                }
            } elseif (preg_match('/^\s*' . preg_quote($raw, '/') . '\s+([A-Z0-9\-]{1,6})\b/u', $rowText, $secAfterCode)) {
                $secCandidate = $this->normalizeSectionTokenFromOcr((string) ($secAfterCode[1] ?? ''), $normalizedCode);
                if ($this->isLikelySectionToken($secCandidate, $normalizedCode)) {
                    $section = $secCandidate;
                }
            }

            $codes[$normalizedCode] = true;
            $parsedRows[] = [
                'code' => $normalizedCode,
                'section' => $section,
                'description' => '',
                'schedule' => $schedule,
                'units' => '',
            ];
        }

        fclose($handle);

        if ($rows !== null) {
            $rows = $parsedRows;
        }

        return [
            'codes' => array_keys($codes),
            'studentNumber' => $studentNumber,
        ];
    }

    /**
     * Check whether OCR output matches the expected loadslip instruction format.
     *
     * @return array{isMatch: bool, score: int, readableRows: int, checks: array<string, bool>, reason: string}
     */
    private function evaluateLoadslipInstructionSignature(string $ocrText, array $rows): array
    {
        $normalized = strtoupper((string) preg_replace('/\s+/u', ' ', trim($ocrText)));

        $uiNoiseTerms = [
            'GUIDE FOR SMS',
            'CURRICULUM',
            'ACTIVE SCHOOLYEAR',
            'ACTIVE SEMESTER',
            'WELCOME,',
            ' LOGOUT',
            ' SMS.NORSU.ONLINE/LOADSLIP',
        ];
        $uiNoiseHitCount = 0;
        foreach ($uiNoiseTerms as $term) {
            if (str_contains($normalized, $term)) {
                $uiNoiseHitCount++;
            }
        }
        $portalUiScreenshotDetected = $uiNoiseHitCount >= 2;

        $checks = [
            'title' => (bool) preg_match('/\bENROLLMENT\s+LOAD\s+SLIP\b/u', $normalized),
            'studentNumberLabel' => (bool) preg_match('/\bSTUDENT\s*NUMBER\b/u', $normalized),
            'nameLabel' => (bool) preg_match('/\bNAME\b/u', $normalized),
            'courseLabel' => (bool) preg_match('/\bCOLLEGE\s*\/\s*COURSE\b|\bCOURSE\b/u', $normalized),
            'yearLevelStatusLabel' => (bool) preg_match('/\bYEAR\s+LEVEL\s+AND\s+STATUS\b/u', $normalized),
            'tableHeader' => (bool) preg_match('/\bSUBJECT\b.*\bSECTION\b.*\bDESCRIPTION\b.*\bSCHEDULE\b/u', $normalized),
            'tableTailHeader' => (bool) preg_match('/\bROOM\b.*\bUNITS?\b/u', $normalized),
            'termLabel' => (bool) preg_match('/\b(FIRST|SECOND|SUMMER)\s+SY\b/u', $normalized),
            'registrarOffice' => (bool) preg_match('/\bOFFICE\s+OF\s+THE\s+UNIVERSITY\s+REGISTRAR\b/u', $normalized),
            'schoolSignature' => (bool) preg_match('/\bNEGROS\s+ORIENTAL\s+STATE\s+UNIVERSITY\b|\bNORSU\b/u', $normalized),
            'encodedPrinted' => (bool) preg_match('/\bENCODED\s+BY\b.*\bPRINTED\s+BY\b/u', $normalized),
            'dateEnrolled' => (bool) preg_match('/\bDATE\s+ENROLLED\b/u', $normalized),
            'refundNote' => (bool) preg_match('/\bNO\s+REFUND\s+FOR\s+WITHDRAWAL\b/u', $normalized),
            'totalSubjects' => (bool) preg_match('/\bTOTAL\s+SUBJECTS?\b/u', $normalized),
            'totalUnits' => (bool) preg_match('/\bTOTAL\s+UNITS?\b/u', $normalized),
            'portalUiNoise' => $portalUiScreenshotDetected,
        ];

        $score = 0;
        foreach ($checks as $passed) {
            if ($passed) {
                $score++;
            }
        }

        $readableRows = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = $this->normalizeSubjectCode((string) ($row['code'] ?? ''));
            $schedule = trim((string) ($row['schedule'] ?? ''));
            if ($code !== '' && $schedule !== '') {
                $readableRows++;
            }
        }

        // Keep core checks strict enough for layout validation, but tolerant of OCR misses
        // on secondary labels.
        $coreMatched = $checks['title']
            && $checks['studentNumberLabel']
            && $checks['tableHeader'];
        $schoolMatched = $checks['schoolSignature'] || $checks['registrarOffice'];
        $rowShapeMatched = $readableRows >= 2;
        $designAnchorCount =
            ((int) $checks['termLabel'])
            + ((int) $checks['dateEnrolled'])
            + ((int) $checks['totalSubjects'])
            + ((int) $checks['totalUnits']);
        $designAnchorsMatched = $designAnchorCount >= 3;
        $isMatch = $coreMatched
            && $schoolMatched
            && $rowShapeMatched
            && $designAnchorsMatched
            && !$portalUiScreenshotDetected
            && $score >= 6;

        $reasonParts = [];
        if (!$coreMatched) {
            $reasonParts[] = 'missing required instruction-layout labels';
        }
        if (!$schoolMatched) {
            $reasonParts[] = 'missing NORSU/registrar header text';
        }
        if (!$rowShapeMatched) {
            $reasonParts[] = 'not enough readable subject rows';
        }
        if (!$designAnchorsMatched) {
            $reasonParts[] = 'missing required loadslip layout anchors';
        }
        if ($portalUiScreenshotDetected) {
            $reasonParts[] = 'detected portal/app UI screenshot noise';
        }
        if ($score < 6) {
            $reasonParts[] = 'format signature score too low';
        }

        return [
            'isMatch' => $isMatch,
            'score' => $score,
            'readableRows' => $readableRows,
            'checks' => $checks,
            'reason' => empty($reasonParts) ? 'ok' : implode('; ', $reasonParts),
        ];
    }

    private function normalizeSemesterLabel(?string $value): string
    {
        $normalized = strtoupper(trim((string) $value));
        if ($normalized === '') {
            return '';
        }

        if (preg_match('/\b(1ST|FIRST)\b/u', $normalized)) {
            return 'FIRST';
        }
        if (preg_match('/\b(2ND|SECOND)\b/u', $normalized)) {
            return 'SECOND';
        }
        if (preg_match('/\b(3RD|THIRD|SUMMER)\b/u', $normalized)) {
            return 'SUMMER';
        }

        return '';
    }

    private function normalizeSchoolYearLabel(?string $value): string
    {
        $normalized = strtoupper(trim((string) $value));
        if ($normalized === '') {
            return '';
        }

        if (preg_match('/\b(20\d{2})\s*[-\/]\s*(20\d{2})\b/u', $normalized, $m)) {
            return (string) ($m[1] ?? '') . '-' . (string) ($m[2] ?? '');
        }

        return '';
    }

    /**
     * @return array{semester: string, schoolYear: string}
     */
    private function getCurrentLoadslipAcademicTerm(): array
    {
        $currentAcademicYear = $this->academicYearRepo->findCurrent() ?? $this->academicYearRepo->findLatestBySequence();

        return [
            'semester' => $this->normalizeSemesterLabel((string) ($currentAcademicYear?->getSemester() ?? '')),
            'schoolYear' => $this->normalizeSchoolYearLabel((string) ($currentAcademicYear?->getYearLabel() ?? '')),
        ];
    }

    private function isStoredLoadslipAcademicTermCurrent(array $data): bool
    {
        $currentTerm = $this->getCurrentLoadslipAcademicTerm();
        if ($currentTerm['semester'] === '' && $currentTerm['schoolYear'] === '') {
            return true;
        }

        $storedSemester = $this->normalizeSemesterLabel((string) ($data['semester'] ?? ''));
        $storedSchoolYear = $this->normalizeSchoolYearLabel((string) ($data['schoolYear'] ?? ''));

        if ($storedSemester === '' || $storedSchoolYear === '') {
            return false;
        }

        if ($currentTerm['semester'] !== '' && $storedSemester !== $currentTerm['semester']) {
            return false;
        }

        if ($currentTerm['schoolYear'] !== '' && $storedSchoolYear !== $currentTerm['schoolYear']) {
            return false;
        }

        return true;
    }

    /**
     * @return array{semester: string, schoolYear: string}
     */
    private function extractLoadslipAcademicTermFromText(string $ocrText): array
    {
        $normalized = strtoupper((string) preg_replace('/\s+/u', ' ', trim($ocrText)));
        if ($normalized === '') {
            return ['semester' => '', 'schoolYear' => ''];
        }

        $semester = '';
        $schoolYear = '';

        if (preg_match('/\b(FIRST|SECOND|SUMMER|1ST|2ND|3RD)\s+(?:SY|SEMESTER)\b/u', $normalized, $mSem)) {
            $semester = $this->normalizeSemesterLabel((string) ($mSem[1] ?? ''));
        } elseif (preg_match('/\bSEMESTER\s*[:\-]?\s*(FIRST|SECOND|SUMMER|1ST|2ND|3RD)\b/u', $normalized, $mSem)) {
            $semester = $this->normalizeSemesterLabel((string) ($mSem[1] ?? ''));
        }

        if (preg_match('/\b(?:SY|SCHOOL\s*YEAR)\s*[:\-]?\s*(20\d{2}\s*[-\/]\s*20\d{2})\b/u', $normalized, $mSy)) {
            $schoolYear = $this->normalizeSchoolYearLabel((string) ($mSy[1] ?? ''));
        } elseif (preg_match('/\b(20\d{2}\s*[-\/]\s*20\d{2})\b/u', $normalized, $mSy)) {
            $schoolYear = $this->normalizeSchoolYearLabel((string) ($mSy[1] ?? ''));
        }

        return [
            'semester' => $semester,
            'schoolYear' => $schoolYear,
        ];
    }

    /**
     * Extract subject codes from OCR by tracing only the subject table rows.
     * Expected row pattern: subject code, section, description, schedule, unit(s).
     * Also extracts student number from top of document.
     *
     * @return array{codes: string[], studentNumber: ?string, formatMatched: bool, formatReason: string, semester: string, schoolYear: string}
     */
    private function parseLoadslipImage(string $path, ?array &$debugData = null, ?array &$rows = null, ?string $expectedStudentNumber = null): array
    {
        if (!class_exists(TesseractOCR::class)) {
            return ['codes' => [], 'studentNumber' => null, 'formatMatched' => false, 'formatReason' => 'ocr_engine_unavailable', 'semester' => '', 'schoolYear' => ''];
        }

        $ocrPreprocessMeta = null;
        $enhancedPath = $this->createEnhancedLoadslipImageForOcr($path, $ocrPreprocessMeta);
        $ocrPath = is_string($enhancedPath) && $enhancedPath !== '' ? $enhancedPath : $path;
        $cleanupEnhancedPath = static function (?string $tempPath): void {
            if (is_string($tempPath) && $tempPath !== '' && is_file($tempPath)) {
                @unlink($tempPath);
            }
        };

        $schedulePattern = $this->getLoadslipSchedulePattern();

        $tesseractExecutable = $this->resolveTesseractExecutable();

        $makeOcr = function (int $psm) use ($ocrPath, $tesseractExecutable): TesseractOCR {
            $ocr = new TesseractOCR($ocrPath);
            if ($tesseractExecutable !== null) {
                $ocr->executable($tesseractExecutable);
            }
            return $ocr->lang('eng')->psm($psm);
        };

        $ocrPsmModes = [6, 11, 4];
        $texts = [];
        $psmModesSucceeded = [];
        $fallbackUsed = false;
        foreach ($ocrPsmModes as $psm) {
            try {
                $ocrText = trim((string) $makeOcr($psm)->run());
                if ($ocrText !== '') {
                    $texts[] = $ocrText;
                    $psmModesSucceeded[] = $psm;
                }
            } catch (\Throwable) {
                // Try next OCR mode.
            }
        }

        if (empty($texts)) {
            try {
                $fallback = new TesseractOCR($ocrPath);
                if ($tesseractExecutable !== null) {
                    $fallback->executable($tesseractExecutable);
                }
                $fallbackText = trim((string) $fallback->run());
                if ($fallbackText !== '') {
                    $fallbackUsed = true;
                    $texts[] = $fallbackText;
                }
            } catch (\Throwable) {
                $cleanupEnhancedPath($enhancedPath);
                return ['codes' => [], 'studentNumber' => null, 'formatMatched' => false, 'formatReason' => 'no_ocr_text_detected', 'semester' => '', 'schoolYear' => ''];
            }

            if (empty($texts)) {
                $cleanupEnhancedPath($enhancedPath);
                return ['codes' => [], 'studentNumber' => null, 'formatMatched' => false, 'formatReason' => 'no_ocr_text_detected', 'semester' => '', 'schoolYear' => ''];
            }
        }

        $codes = [];
        $rawCandidates = [];
        $parsedRows = [];
        $studentNumber = null;
        $text = strtoupper(implode("\n", array_filter($texts)));
        $termDetection = $this->extractLoadslipAcademicTermFromText($text);
        $detectedSemester = $this->normalizeSemesterLabel((string) ($termDetection['semester'] ?? ''));
        $detectedSchoolYear = $this->normalizeSchoolYearLabel((string) ($termDetection['schoolYear'] ?? ''));
        $lines = preg_split('/\R/u', $text) ?: [];
        $tableWindow = $this->detectLoadslipTableWindow($lines, $schedulePattern);
        $linePassIndexes = [];
        if (is_array($tableWindow)) {
            $start = (int) ($tableWindow['start'] ?? 0);
            $end = (int) ($tableWindow['end'] ?? -1);
            if ($start >= 0 && $end >= $start) {
                for ($idx = $start; $idx <= $end; $idx++) {
                    $linePassIndexes[] = $idx;
                }
            }
        }
        if (empty($linePassIndexes)) {
            $linePassIndexes = array_keys($lines);
        }
        $rowTrace = [];
        $rejectedTrace = [];
        $subjectCodeTrace = [];
        $maxParsedRows = 10;
        $passStats = [
            'line' => 0,
            'line_pair' => 0,
            'flat' => 0,
            'segment' => 0,
        ];

        $pushTrace = static function (array &$bucket, array $entry, int $max = 180): void {
            if (count($bucket) >= $max) {
                return;
            }
            $bucket[] = $entry;
        };
        $sanitizeTraceSource = fn(string $value): string => mb_substr($this->sanitizeLoadslipTraceSource($value), 0, 200);

        // Extract student number using robust detection
        $studentNumber = $this->extractStudentNumber($text, $expectedStudentNumber);

        $currentUser = $this->getUser();
        $studentUser = $currentUser instanceof \App\Entity\User ? $currentUser : null;
        $curriculumCodeMap = $this->getCurriculumSubjectCodeMapForUser($studentUser, $this->curriculumRepo);
        $knownCodeByCompact = $this->buildKnownSubjectCodeByCompact($this->subjectRepo, $curriculumCodeMap);

        $rowSeen = [];
        $rowIndexByCodeSchedule = [];
        $addParsedRow = function (array $row, string $pass, string $source, array $traceMeta = []) use (&$parsedRows, &$rawCandidates, &$codes, &$rowSeen, &$rowIndexByCodeSchedule, &$passStats, &$rowTrace, &$subjectCodeTrace, $pushTrace, $maxParsedRows, $sanitizeTraceSource, $knownCodeByCompact, $curriculumCodeMap): bool {
            $rawCode = trim((string) ($row['code'] ?? ''));
            $code = $this->normalizeSubjectCode($rawCode);
            $rawSection = trim((string) ($row['section'] ?? ''));
            $rawDescription = trim((string) ($row['description'] ?? ''));
            $sectionSource = trim((string) ($row['sectionSource'] ?? ($rawSection !== '' ? 'row' : 'missing')));
            $descriptionSource = trim((string) ($row['descriptionSource'] ?? 'row'));
            $section = $rawSection;
            $schedule = trim((string) ($row['schedule'] ?? ''));
            $description = $this->normalizeLoadslipDescription($rawDescription, $code);
            if ($section === '' && $description !== '' && preg_match('/^[\.:|\-]*\s*([A-Z0-9]{1,6})\s+(.+)$/u', $description, $mDescSection)) {
                $descSection = trim((string) ($mDescSection[1] ?? ''));
                $descBody = trim((string) ($mDescSection[2] ?? ''));
                if ($this->isLikelySectionToken($descSection, $code) && preg_match('/[A-Z]{4,}/u', strtoupper($descBody))) {
                    $section = $descSection;
                    $sectionSource = 'description_prefix';
                    $description = $this->normalizeLoadslipDescription($descBody, $code);
                    $descriptionSource = 'description_prefix_trim';
                }
            }
            $units = trim((string) ($row['units'] ?? ''));
            $sourcePreview = $sanitizeTraceSource((string) $source);
            $lineNumber = isset($traceMeta['lineNumber']) ? (int) $traceMeta['lineNumber'] : null;
            $lineNumberEnd = isset($traceMeta['lineNumberEnd']) ? (int) $traceMeta['lineNumberEnd'] : null;
            $textOffset = isset($traceMeta['textOffset']) ? (int) $traceMeta['textOffset'] : null;
            $tableRowHint = isset($traceMeta['tableRow']) ? (int) $traceMeta['tableRow'] : null;
            $columnCount = isset($row['columnCount']) ? (int) $row['columnCount'] : (isset($traceMeta['columnCount']) ? (int) $traceMeta['columnCount'] : 0);
            $columnsPreview = [];
            if (is_array($row['columnsPreview'] ?? null)) {
                $columnsPreview = array_values(array_slice((array) ($row['columnsPreview'] ?? []), 0, 8));
            } elseif (is_array($traceMeta['columnsPreview'] ?? null)) {
                $columnsPreview = array_values(array_slice((array) ($traceMeta['columnsPreview'] ?? []), 0, 8));
            }
            $columnMap = is_array($row['columnMap'] ?? null) ? (array) ($row['columnMap'] ?? []) : [];

            $traceDecision = function (bool $accepted, string $reason) use (&$subjectCodeTrace, $pushTrace, $pass, $rawCode, $code, $rawSection, $section, $sectionSource, $schedule, $rawDescription, $description, $descriptionSource, $units, $sourcePreview, $lineNumber, $lineNumberEnd, $textOffset, $tableRowHint, $columnCount, $columnsPreview, $columnMap): void {
                $pushTrace($subjectCodeTrace, [
                    'pass' => $pass,
                    'raw' => $rawCode,
                    'normalized' => $code,
                    'rawSection' => $rawSection,
                    'section' => $section,
                    'sectionSource' => $sectionSource,
                    'schedule' => $schedule,
                    'rawDescription' => $rawDescription,
                    'description' => $description,
                    'descriptionSource' => $descriptionSource,
                    'units' => $units,
                    'accepted' => $accepted,
                    'reason' => $reason,
                    'source' => $sourcePreview,
                    'lineNumber' => $lineNumber,
                    'lineNumberEnd' => $lineNumberEnd,
                    'textOffset' => $textOffset,
                    'tableRow' => $tableRowHint,
                    'columnCount' => $columnCount,
                    'columnsPreview' => $columnsPreview,
                    'columnMap' => $columnMap,
                ], 260);
            };

            if ($code === '' || $schedule === '') {
                $traceDecision(false, 'missing_code_or_schedule');
                return false;
            }

            $strictOcrCode = $this->isLikelyOcrSubjectCode($code);
            $acceptedKnownShortTail = false;
            if (!$strictOcrCode) {
                if (!$this->isLikelySubjectCode($code)) {
                    $traceDecision(false, 'invalid_subject_code_shape');
                    return false;
                }

                $resolvedKnownCode = $this->resolveToKnownSubjectCode($code, $knownCodeByCompact);
                $isKnownShortTailCode = $resolvedKnownCode !== ''
                    && (empty($curriculumCodeMap) || isset($curriculumCodeMap[$resolvedKnownCode]));

                if ($isKnownShortTailCode) {
                    $code = $resolvedKnownCode;
                    $description = $this->normalizeLoadslipDescription($description, $code);
                    $acceptedKnownShortTail = true;
                }

                if (!$acceptedKnownShortTail) {
                    $looseTail = $this->extractSubjectCodeLooseNumericTail($code);
                    $prefix = $this->extractSubjectCodePrefix($code);
                    if ($looseTail === '' || strlen($looseTail) > 2) {
                        $traceDecision(false, 'weak_tail_not_recoverable');
                        return false;
                    }

                    if (in_array($prefix, ['TH', 'T', 'M', 'W', 'F', 'SU', 'AM', 'PM', 'OO', 'OF', 'BY'], true)) {
                        $traceDecision(false, 'noise_prefix_short_tail');
                        return false;
                    }

                    $descUpper = strtoupper($description);
                    if ($descUpper === '' || !preg_match('/[A-Z]{4,}/u', $descUpper)) {
                        $traceDecision(false, 'short_tail_without_description_context');
                        return false;
                    }

                    if (preg_match('/\b(STUDENT|DATE|PRINTED|ENCODED|ANONYM|NUMBER|BIRTH|ENROLLED|SCHOLAR)\b/u', $descUpper)) {
                        $traceDecision(false, 'metadata_context_row');
                        return false;
                    }
                }
            }

            $sectionCandidate = $this->normalizeSectionTokenFromOcr($section, $code);
            if ($section !== '' && $sectionCandidate !== '' && $section !== $sectionCandidate) {
                $section = $sectionCandidate;
                $sectionSource = $sectionSource !== '' ? $sectionSource . '+ocr_section_normalized' : 'ocr_section_normalized';
            }

            if ($section !== '' && !$this->isLikelySectionToken($section, $code)) {
                $sectionSource = 'dropped_invalid_section';
                $section = '';
            }

            // Reject obvious merged/noisy rows (e.g., room tokens becoming subject codes).
            if ($units !== '' && is_numeric($units) && (float) $units > 10) {
                $traceDecision(false, 'units_out_of_range');
                return false;
            }

            if ($description !== '' && preg_match_all('/\b([A-Z]{2,8}\s?\d{3,4}[A-Z]?)\b/u', strtoupper($description), $descCodeMatches)) {
                foreach (($descCodeMatches[1] ?? []) as $descCodeRaw) {
                    $descCode = $this->normalizeSubjectCode((string) $descCodeRaw);
                    if ($descCode !== '' && !$this->subjectCodesAreCompatible($code, $descCode)) {
                        $traceDecision(false, 'description_contains_conflicting_code');
                        return false;
                    }
                }
            }

            $normalizedSection = $this->normalizeSectionValue($section);
            $normalizedSchedule = $this->normalizeScheduleValue($schedule);

            // Low-confidence OCR row: keep only when there is enough context besides a weak code/schedule pair.
            if (!$strictOcrCode && $normalizedSection === '' && $description === '') {
                $parsedSchedule = $this->parseNormalizedScheduleToken($normalizedSchedule);
                $scheduleHasDay = trim((string) ($parsedSchedule['day'] ?? '')) !== '';
                if (!$scheduleHasDay) {
                    $traceDecision(false, 'weak_row_without_context');
                    return false;
                }
            }

            $codeScheduleKey = $code . '|' . $normalizedSchedule;
            if (isset($rowIndexByCodeSchedule[$codeScheduleKey])) {
                $existingIndex = (int) $rowIndexByCodeSchedule[$codeScheduleKey];
                if (isset($parsedRows[$existingIndex]) && is_array($parsedRows[$existingIndex])) {
                    $existingSection = trim((string) ($parsedRows[$existingIndex]['section'] ?? ''));
                    $existingDescription = trim((string) ($parsedRows[$existingIndex]['description'] ?? ''));
                    $existingUnits = trim((string) ($parsedRows[$existingIndex]['units'] ?? ''));
                    $updated = false;

                    if ($existingSection === '' && $normalizedSection !== '') {
                        $parsedRows[$existingIndex]['section'] = $section;
                        $updated = true;
                    }

                    if ($existingDescription === '' && $description !== '') {
                        $parsedRows[$existingIndex]['description'] = $description;
                        $updated = true;
                    }

                    if ($existingUnits === '' && $units !== '') {
                        $parsedRows[$existingIndex]['units'] = $units;
                        $updated = true;
                    }

                    if ($updated) {
                        $pushTrace($rowTrace, [
                            'pass' => $pass,
                            'tableRow' => $existingIndex + 1,
                            'lineNumber' => $lineNumber,
                            'lineNumberEnd' => $lineNumberEnd,
                            'textOffset' => $textOffset,
                            'columnCount' => $columnCount,
                            'columnsPreview' => $columnsPreview,
                            'columnMap' => $columnMap,
                            'code' => $code,
                            'section' => (string) ($parsedRows[$existingIndex]['section'] ?? ''),
                            'sectionSource' => $sectionSource,
                            'schedule' => $schedule,
                            'description' => (string) ($parsedRows[$existingIndex]['description'] ?? ''),
                            'descriptionSource' => $descriptionSource,
                            'units' => (string) ($parsedRows[$existingIndex]['units'] ?? ''),
                            'source' => $sourcePreview,
                            'merge' => true,
                        ], 240);
                        $traceDecision(false, 'merged_into_existing_code_schedule');
                    } else {
                        $traceDecision(false, 'duplicate_code_schedule');
                    }

                    return false;
                }
            }

            if (count($parsedRows) >= $maxParsedRows) {
                $traceDecision(false, 'max_rows_reached');
                return false;
            }

            $key = $code
                . '|' . $normalizedSection
                . '|' . $normalizedSchedule;
            if (isset($rowSeen[$key])) {
                $traceDecision(false, 'duplicate_row_key');
                return false;
            }

            $rawCandidates[$code] = true;
            $codes[$code] = true;
            $parsedRows[] = [
                'code' => $code,
                'section' => $section,
                'description' => $description,
                'schedule' => $schedule,
                'units' => $units,
            ];
            $acceptedTableRow = count($parsedRows);
            $rowIndexByCodeSchedule[$codeScheduleKey] = count($parsedRows) - 1;
            $rowSeen[$key] = true;
            $passStats[$pass] = (int) ($passStats[$pass] ?? 0) + 1;

            $pushTrace($rowTrace, [
                'pass' => $pass,
                'tableRow' => $acceptedTableRow,
                'lineNumber' => $lineNumber,
                'lineNumberEnd' => $lineNumberEnd,
                'textOffset' => $textOffset,
                'columnCount' => $columnCount,
                'columnsPreview' => $columnsPreview,
                'columnMap' => $columnMap,
                'code' => $code,
                'section' => $section,
                'sectionSource' => $sectionSource,
                'schedule' => $schedule,
                'description' => $description,
                'descriptionSource' => $descriptionSource,
                'units' => $units,
                'source' => $sourcePreview,
            ], 240);

            $acceptReason = 'accepted_tentative_short_tail';
            if ($strictOcrCode) {
                $acceptReason = 'accepted_strict';
            } elseif ($acceptedKnownShortTail) {
                $acceptReason = 'accepted_known_short_tail';
            }
            $traceDecision(true, $acceptReason);

            return true;
        };

        foreach ($linePassIndexes as $lineIndex) {
            $lineRaw = (string) ($lines[$lineIndex] ?? '');
            $line = trim((string) $lineRaw);
            if ($line === '') {
                continue;
            }

            $lineColumns = array_values(array_filter(array_map('trim', preg_split('/\t+|\s{2,}/u', strtoupper($line)) ?: []), static fn(string $v): bool => $v !== ''));

            $row = $this->extractOcrRowFromLine($line, $schedulePattern);
            if (!is_array($row)) {
                $upperLine = strtoupper($line);
                $hasCodeLike = (bool) preg_match('/\b([A-Z]{2,8}\s*-?\s*[0-9OQDILSZGB]{1,4}[A-Z]?|[A-Z0-9]{2,8}\s*-?\s*[A-Z0-9]{1,6}|[A-Z0-9]{4,12})\b/u', $upperLine);
                $hasTimeLike = (bool) preg_match('/\b\d{1,2}(?:(?::|\.)\d{2})?\s*-\s*\d{1,2}(?:(?::|\.)\d{2})?\b/u', $upperLine);
                if ($hasCodeLike || $hasTimeLike) {
                    $pushTrace($rejectedTrace, [
                        'pass' => 'line',
                        'reason' => 'could_not_extract_row',
                        'lineNumber' => $lineIndex + 1,
                        'columnCount' => count($lineColumns),
                        'columnsPreview' => array_slice($lineColumns, 0, 8),
                        'source' => $sanitizeTraceSource($line),
                    ], 160);
                }
                continue;
            }

            if (!$addParsedRow($row, 'line', $line, [
                'lineNumber' => $lineIndex + 1,
                'columnCount' => count($lineColumns),
                'columnsPreview' => array_slice($lineColumns, 0, 8),
            ])) {
                $pushTrace($rejectedTrace, [
                    'pass' => 'line',
                    'reason' => 'duplicate_or_invalid',
                    'lineNumber' => $lineIndex + 1,
                    'columnCount' => (int) ($row['columnCount'] ?? count($lineColumns)),
                    'columnsPreview' => is_array($row['columnsPreview'] ?? null) ? array_slice((array) ($row['columnsPreview'] ?? []), 0, 8) : array_slice($lineColumns, 0, 8),
                    'code' => (string) ($row['code'] ?? ''),
                    'section' => (string) ($row['section'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                    'schedule' => (string) ($row['schedule'] ?? ''),
                    'units' => (string) ($row['units'] ?? ''),
                    'source' => $sanitizeTraceSource($line),
                ], 160);
            }
        }

        // Recovery pass: handle OCR-wrapped rows where code/section and schedule are split into adjacent lines.
        $lineCount = count($lines);
        $pairStart = 0;
        $pairEnd = max(-1, $lineCount - 1);
        if (is_array($tableWindow)) {
            $pairStart = max(0, (int) ($tableWindow['start'] ?? 0));
            $pairEnd = min($lineCount - 1, (int) ($tableWindow['end'] ?? ($lineCount - 1)));
        }

        for ($i = $pairStart; $i < $pairEnd; $i++) {
            $first = trim((string) ($lines[$i] ?? ''));
            $second = trim((string) ($lines[$i + 1] ?? ''));
            if ($first === '' || $second === '') {
                continue;
            }

            $firstUpper = strtoupper($first);
            $secondUpper = strtoupper($second);
            $firstHasCode = (bool) preg_match('/\b([A-Z]{2,8}\s*-?\s*[0-9OQDILSZGB]{1,4}[A-Z]?|[A-Z0-9]{2,8}\s*-?\s*[A-Z0-9]{1,6}|[A-Z0-9]{4,12})\b/u', $firstUpper);
            $firstHasSchedule = $this->extractScheduleFromText($firstUpper, $schedulePattern) !== '';
            $secondHasSchedule = $this->extractScheduleFromText($secondUpper, $schedulePattern) !== '';

            if (!$firstHasCode || $firstHasSchedule || !$secondHasSchedule) {
                continue;
            }

            $combined = $first . ' ' . $second;
            $combinedColumns = array_values(array_filter(array_map('trim', preg_split('/\t+|\s{2,}/u', strtoupper($combined)) ?: []), static fn(string $v): bool => $v !== ''));
            $row = $this->extractOcrRowFromLine($combined, $schedulePattern);
            if (!is_array($row)) {
                $pushTrace($rejectedTrace, [
                    'pass' => 'line_pair',
                    'reason' => 'combine_failed',
                    'lineNumber' => $i + 1,
                    'lineNumberEnd' => $i + 2,
                    'columnCount' => count($combinedColumns),
                    'columnsPreview' => array_slice($combinedColumns, 0, 8),
                    'source' => $sanitizeTraceSource($combined),
                ], 160);
                continue;
            }

            if (!$addParsedRow($row, 'line_pair', $combined, [
                'lineNumber' => $i + 1,
                'lineNumberEnd' => $i + 2,
                'columnCount' => count($combinedColumns),
                'columnsPreview' => array_slice($combinedColumns, 0, 8),
            ])) {
                $pushTrace($rejectedTrace, [
                    'pass' => 'line_pair',
                    'reason' => 'duplicate_or_invalid',
                    'lineNumber' => $i + 1,
                    'lineNumberEnd' => $i + 2,
                    'columnCount' => (int) ($row['columnCount'] ?? count($combinedColumns)),
                    'columnsPreview' => is_array($row['columnsPreview'] ?? null) ? array_slice((array) ($row['columnsPreview'] ?? []), 0, 8) : array_slice($combinedColumns, 0, 8),
                    'code' => (string) ($row['code'] ?? ''),
                    'section' => (string) ($row['section'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                    'schedule' => (string) ($row['schedule'] ?? ''),
                    'units' => (string) ($row['units'] ?? ''),
                    'source' => $sanitizeTraceSource($combined),
                ], 160);
            }
        }

        // Secondary pass: match rows from flattened OCR text to recover missed middle lines.
        $shouldRunRecoveryPasses = count($parsedRows) < 3;

        $flatSource = $text;
        if (is_array($tableWindow)) {
            $start = max(0, (int) ($tableWindow['start'] ?? 0));
            $end = min(count($lines) - 1, (int) ($tableWindow['end'] ?? (count($lines) - 1)));
            if ($end >= $start) {
                $slice = array_slice($lines, $start, ($end - $start) + 1);
                $sliceText = strtoupper(trim(implode(' ', array_map(static fn($v): string => trim((string) $v), $slice))));
                if ($sliceText !== '') {
                    $flatSource = $sliceText;
                }
            }
        }

        $flatText = (string) preg_replace('/\s+/u', ' ', $flatSource);
        if ($shouldRunRecoveryPasses && $flatText !== '') {
            if (preg_match_all('/\b([A-Z0-9]{2,}\s*-?\s*[A-Z0-9]{1,6})(?:\s+([A-Z0-9]{1,4}))?\s+(.{1,120}?)\s+((?:[A-Z]{1,3}(?:\s*-\s*[A-Z]{1,3}){1,2}|MWF|TTH|THF)\s+\d{1,2}(?:(?::|\.)\d{2})?\s*-\s*\d{1,2}(?:(?::|\.)\d{2})?\s*(?:A\.?M\.?|P\.?M\.?|AM|PM)?|\d{1,2}(?:(?::|\.)\d{2})?\s*-\s*\d{1,2}(?:(?::|\.)\d{2})?\s*(?:A\.?M\.?|P\.?M\.?|AM|PM)?)\s+([A-Z0-9\s]{1,20})\s+(\d+(?:\.\d+)?)\b/u', $flatText, $rowMatches, PREG_SET_ORDER)) {
                foreach ($rowMatches as $matchIndex => $m) {
                    $matchText = (string) ($m[0] ?? '');
                    $flatOffset = strpos($flatText, $matchText);
                    $normalized = $this->normalizeSubjectCode((string) ($m[1] ?? ''));
                    if (!$this->isLikelySubjectCode($normalized)) {
                        $pushTrace($rejectedTrace, [
                            'pass' => 'flat',
                            'reason' => 'invalid_subject_code_shape',
                            'textOffset' => is_int($flatOffset) ? $flatOffset : null,
                            'source' => $sanitizeTraceSource($matchText),
                        ], 160);
                        continue;
                    }
                    $section = trim((string) ($m[2] ?? ''));
                    if (!$this->isLikelySectionToken($section, $normalized)) {
                        $section = '';
                    }
                    $description = trim((string) ($m[3] ?? ''));
                    $schedule = trim((string) ($m[4] ?? ''));
                    $units = trim((string) ($m[6] ?? ''));

                    if ($normalized === '' || $schedule === '') {
                        $pushTrace($rejectedTrace, [
                            'pass' => 'flat',
                            'reason' => 'missing_schedule',
                            'textOffset' => is_int($flatOffset) ? $flatOffset : null,
                            'code' => $normalized,
                            'section' => $section,
                            'description' => $description,
                            'schedule' => $schedule,
                            'units' => $units,
                            'source' => $sanitizeTraceSource($matchText),
                        ], 160);
                        continue;
                    }

                    $row = [
                        'code' => $normalized,
                        'section' => $section,
                        'description' => $description,
                        'schedule' => $schedule,
                        'units' => $units,
                        'sectionSource' => 'flat_capture',
                        'descriptionSource' => 'flat_capture',
                    ];
                    if (!$addParsedRow($row, 'flat', $matchText, [
                        'textOffset' => is_int($flatOffset) ? $flatOffset : null,
                        'tableRow' => $matchIndex + 1,
                    ])) {
                        $pushTrace($rejectedTrace, [
                            'pass' => 'flat',
                            'reason' => 'duplicate_or_invalid',
                            'textOffset' => is_int($flatOffset) ? $flatOffset : null,
                            'code' => (string) ($row['code'] ?? ''),
                            'section' => (string) ($row['section'] ?? ''),
                            'description' => (string) ($row['description'] ?? ''),
                            'schedule' => (string) ($row['schedule'] ?? ''),
                            'units' => (string) ($row['units'] ?? ''),
                            'source' => $sanitizeTraceSource($matchText),
                        ], 160);
                    }
                }
            }
        }

        // Tertiary pass: scan each subject-code segment and recover missing section/schedule rows.
        if ($shouldRunRecoveryPasses && $flatText !== '') {
            $rowSeen = [];
            foreach ($parsedRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $key = $this->normalizeSubjectCode((string) ($row['code'] ?? ''))
                    . '|' . $this->normalizeSectionValue((string) ($row['section'] ?? ''))
                    . '|' . $this->normalizeScheduleValue((string) ($row['schedule'] ?? ''));
                $rowSeen[$key] = true;
            }

            if (preg_match_all('/\b([A-Z0-9]{2,}\s*-?\s*[A-Z0-9]{1,6})\b/u', $flatText, $codeMatches, PREG_OFFSET_CAPTURE)) {
                $matchCount = count($codeMatches[1]);
                for ($i = 0; $i < $matchCount; $i++) {
                    $rawCode = (string) ($codeMatches[1][$i][0] ?? '');
                    $offset = (int) ($codeMatches[1][$i][1] ?? 0);
                    $normalized = $this->normalizeSubjectCode($rawCode);
                    if (!$this->isLikelySubjectCode($normalized)) {
                        $pushTrace($rejectedTrace, [
                            'pass' => 'segment',
                            'reason' => 'invalid_subject_code_shape',
                            'textOffset' => $offset,
                            'source' => $sanitizeTraceSource($rawCode),
                        ], 160);
                        continue;
                    }

                    $nextOffset = $i + 1 < $matchCount ? (int) ($codeMatches[1][$i + 1][1] ?? strlen($flatText)) : strlen($flatText);
                    $segmentLen = max(80, min(240, $nextOffset - $offset));
                    $segment = trim((string) substr($flatText, $offset, $segmentLen));
                    if ($segment === '') {
                        continue;
                    }

                    $section = '';
                    if (preg_match('/^\s*[A-Z0-9]{2,}\s*-?\s*[A-Z0-9]{1,6}(?:\s+([A-Z0-9\-]{1,6}))?\b/u', $segment, $secMatch)) {
                        $secCandidate = trim((string) ($secMatch[1] ?? ''));
                        if ($this->isLikelySectionToken($secCandidate, $normalized)) {
                            $section = $secCandidate;
                        }
                    }

                    if (!preg_match($schedulePattern, $segment, $schedMatch)) {
                        $pushTrace($rejectedTrace, [
                            'pass' => 'segment',
                            'reason' => 'missing_schedule',
                            'textOffset' => $offset,
                            'source' => $sanitizeTraceSource($segment),
                        ], 160);
                        continue;
                    }
                    $schedule = trim((string) ($schedMatch[1] ?? ''));
                    if ($schedule === '') {
                        continue;
                    }

                    $description = '';
                    $units = '';
                    $afterCode = preg_replace('/^\s*' . preg_quote($rawCode, '/') . '(?:\s+' . preg_quote($section, '/') . ')?\s*/u', '', $segment, 1);
                    if (is_string($afterCode) && $afterCode !== '') {
                        $beforeSchedule = strstr($afterCode, $schedule, true);
                        if ($beforeSchedule !== false) {
                            $description = trim((string) $beforeSchedule);
                        }
                    }
                    if (preg_match('/\b(\d+(?:\.\d+)?)\b(?!.*\b\d+(?:\.\d+)?\b)/u', $segment, $unitMatch)) {
                        $units = trim((string) ($unitMatch[1] ?? ''));
                    }

                    $row = [
                        'code' => $normalized,
                        'section' => $section,
                        'description' => $description,
                        'schedule' => $schedule,
                        'units' => $units,
                        'sectionSource' => $section !== '' ? 'segment_prefix' : 'segment_missing',
                        'descriptionSource' => 'segment_slice',
                    ];

                    if (!$addParsedRow($row, 'segment', $segment, [
                        'textOffset' => $offset,
                        'tableRow' => $i + 1,
                    ])) {
                        $pushTrace($rejectedTrace, [
                            'pass' => 'segment',
                            'reason' => 'duplicate_or_invalid',
                            'textOffset' => $offset,
                            'code' => (string) ($row['code'] ?? ''),
                            'section' => (string) ($row['section'] ?? ''),
                            'description' => (string) ($row['description'] ?? ''),
                            'schedule' => (string) ($row['schedule'] ?? ''),
                            'units' => (string) ($row['units'] ?? ''),
                            'source' => $sanitizeTraceSource($segment),
                        ], 160);
                    }
                }
            }
        }

        if ($debugData !== null) {
            $previewText = trim($text);
            if (mb_strlen($previewText) > 4000) {
                $previewText = mb_substr($previewText, 0, 4000) . "\n... [truncated]";
            }

            $signatureCheck = $this->evaluateLoadslipInstructionSignature($text, $parsedRows);

            $scanDetection = [
                'lineCount' => count($lines),
                'linePassCount' => count($linePassIndexes),
                'tableWindowDetected' => is_array($tableWindow),
                'tableWindowStart' => is_array($tableWindow) ? ((int) ($tableWindow['start'] ?? -1) + 1) : null,
                'tableWindowEnd' => is_array($tableWindow) ? ((int) ($tableWindow['end'] ?? -1) + 1) : null,
                'recoveryPassesEnabled' => $shouldRunRecoveryPasses,
                'psmModesTried' => $ocrPsmModes,
                'psmModesSucceeded' => array_values(array_unique(array_map('intval', $psmModesSucceeded))),
                'fallbackUsed' => $fallbackUsed,
            ];

            $debugData = [
                'ocrText' => $previewText,
                'ocrPreprocess' => is_array($ocrPreprocessMeta) ? $ocrPreprocessMeta : ['applied' => false, 'source' => 'original'],
                'rawCandidates' => array_keys($rawCandidates),
                'matchedCodes' => array_keys($codes),
                'validCodes' => array_keys($codes),
                'rowTrace' => $rowTrace,
                'rejectedTrace' => $rejectedTrace,
                'subjectCodeTrace' => $subjectCodeTrace,
                'passStats' => $passStats,
                'rowCount' => count($parsedRows),
                'studentNumber' => $studentNumber,
                'semester' => $detectedSemester,
                'schoolYear' => $detectedSchoolYear,
                'instructionSignature' => $signatureCheck,
                'tableWindow' => is_array($tableWindow) ? $tableWindow : null,
                'scanDetection' => $scanDetection,
            ];
        }

        $signatureCheck = $this->evaluateLoadslipInstructionSignature($text, $parsedRows);

        if ($rows !== null) {
            $rows = $parsedRows;
        }

        $cleanupEnhancedPath($enhancedPath);

        return [
            'codes' => array_keys($codes),
            'studentNumber' => $studentNumber,
            'formatMatched' => (bool) ($signatureCheck['isMatch'] ?? false),
            'formatReason' => (string) ($signatureCheck['reason'] ?? ''),
            'semester' => $detectedSemester,
            'schoolYear' => $detectedSchoolYear,
        ];
    }

    #[Route('/qr/{id}', name: 'evaluation_qr_redirect', methods: ['GET', 'POST'])]
    #[Route('/qr/{id}/{subjectId}', name: 'evaluation_qr_redirect_with_subject', methods: ['GET', 'POST'])]
    #[Route('/qr/{id}/{subjectId}/{section}', name: 'evaluation_qr_redirect_with_section', methods: ['GET', 'POST'])]
    public function qrRedirect(
        int $id,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        SubjectRepository $subjectRepo,
        UserRepository $userRepo,
        QuestionRepository $questionRepo,
        QuestionCategoryDescriptionRepository $descRepo,
        EvaluationResponseRepository $responseRepo,
        FacultySubjectLoadRepository $fslRepo,
        AcademicYearRepository $ayRepo,
        DepartmentRepository $deptRepo,
        EntityManagerInterface $em,
        ?int $subjectId = null,
        ?string $section = null,
    ): Response {
        $eval = $evalRepo->find($id);
        if (!$eval || !$eval->isOpen() || $eval->getEvaluationType() !== 'SET') {
            $this->addFlash('danger', 'This evaluation is not available.');
            return $this->redirectToRoute('app_login');
        }

        // If subject ID is provided, use it directly
        $subject = null;
        $sectionFromLoad = '';
        $schedule = '';
        $sectionParam = null; // Store the section parameter separately

        if ($subjectId) {
            $subject = $subjectRepo->find($subjectId);

            // Fetch section and schedule from FacultySubjectLoad
            if ($subject && $eval->getFaculty()) {
                $facultyName = $eval->getFaculty();
                $facultyUsers = $userRepo->createQueryBuilder('u')
                    ->where('CONCAT(u.lastName, \', \', u.firstName) = :fullName')
                    ->orWhere('CONCAT(u.firstName, \' \', u.lastName) = :fullName')
                    ->setParameter('fullName', $facultyName)
                    ->getQuery()->getResult();

                if (!empty($facultyUsers)) {
                    $facultyUser = $facultyUsers[0];
                    $currentAY = $ayRepo->findCurrent();

                    // If section parameter is provided, search for that specific section
                    if ($section) {
                        $load = $fslRepo->findOneBy([
                            'faculty' => $facultyUser,
                            'subject' => $subject,
                            'section' => $section,
                            'academicYear' => $currentAY
                        ]);
                        $sectionParam = strtoupper(trim($section));
                    } else {
                        // Otherwise, get the first load
                        $load = $fslRepo->findOneBy([
                            'faculty' => $facultyUser,
                            'subject' => $subject,
                            'academicYear' => $currentAY
                        ]);
                    }

                    if ($load) {
                        $sectionFromLoad = strtoupper(trim((string) ($load->getSection() ?? '')));
                        $schedule = trim((string) ($load->getSchedule() ?? ''));
                    }
                }
            }
        }

        // Determine final section to display
        $finalSection = $sectionParam ?? $sectionFromLoad;
        if ($schedule === '' && method_exists($eval, 'getTime')) {
            $schedule = trim((string) ($eval->getTime() ?? ''));
        }

        // Otherwise, resolve subject from evaluation's subject string ("CODE — Name")
        if (!$subject) {
            $subjectStr = $eval->getSubject();
            if ($subjectStr) {
                $parts = explode(' — ', $subjectStr, 2);
                $code = trim($parts[0]);
                $subject = $subjectRepo->findOneBy(['subjectCode' => $code]);
            }
        }

        if (!$subject) {
            $this->addFlash('danger', 'Subject not found for this evaluation.');
            return $this->redirectToRoute('app_login');
        }

        // Resolve faculty
        $faculty = $subject->getFaculty();
        if (!$faculty && $eval->getFaculty()) {
            $faculty = $userRepo->findOneByFullName($eval->getFaculty());
        }
        if (!$faculty) {
            $this->addFlash('danger', 'No faculty assigned to this evaluation.');
            return $this->redirectToRoute('app_login');
        }

        $questions = $questionRepo->findByType('SET');
        $error = null;
        $success = false;
        $verificationSessionKey = sprintf(
            'qr_verified_student_%d_%d_%s',
            $eval->getId(),
            $subject->getId(),
            strtoupper($finalSection !== '' ? $finalSection : 'NA')
        );

        $verifiedStudentId = (int) $request->getSession()->get($verificationSessionKey, 0);
        $verifiedStudent = $verifiedStudentId > 0 ? $userRepo->find($verifiedStudentId) : null;
        if ($verifiedStudent && !$verifiedStudent->isStudent()) {
            $verifiedStudent = null;
            $request->getSession()->remove($verificationSessionKey);
        }
        if ($verifiedStudent) {
            $stillVerified = $this->isLoadslipVerifiedForStudent($request, (string) $verifiedStudent->getSchoolId())
                && $this->qrLoadslipRowMatches(
                    $request,
                    (string) $verifiedStudent->getSchoolId(),
                    (string) $subject->getSubjectCode(),
                    $finalSection !== '' ? $finalSection : null,
                    $schedule !== '' ? $schedule : null,
                    (string) ($subject->getSubjectName() ?? '')
                );
            if (!$stillVerified) {
                $verifiedStudent = null;
                $request->getSession()->remove($verificationSessionKey);
                $error = 'Loadslip verification is required before entering the form.';
            }
        }

        if ($request->isMethod('POST')) {
            $action = trim((string) $request->request->get('_action', ''));
            $schoolId = trim($request->request->get('school_id', ''));
            $privacyConsent = trim((string) $request->request->get('privacy_consent', ''));

            if ($action === 'verify_student') {
                if ($schoolId === '') {
                    $error = 'Please enter your Student ID.';
                } else {
                    $student = $userRepo->findOneBy(['schoolId' => $schoolId]);
                    if (!$student || !$student->isStudent()) {
                        $error = 'Student ID not found. Please create an account.';
                    } elseif ($student->getAccountStatus() !== 'active') {
                        $error = 'Your account is inactive. Please contact your administrator.';
                    } else {
                        $normalizedSchoolId = $this->normalizeStudentNumber($schoolId);
                        $isVerified = $this->isLoadslipVerifiedForStudent($request, $schoolId);

                        // Legacy metadata recovery: only if imported loadslip rows/codes already exist.
                        if (!$isVerified && $normalizedSchoolId !== '' && $normalizedSchoolId === $this->normalizeStudentNumber((string) $student->getSchoolId())) {
                            $session = $request->getSession();
                            $legacyCodes = (array) $session->get('student_loadslip_codes', []);
                            $legacyRows = (array) $session->get('student_loadslip_rows', []);

                            if (!empty($legacyCodes) || !empty($legacyRows)) {
                                $legacyPreviewPath = $this->normalizeLoadslipPreviewPath((string) $session->get('student_loadslip_preview_path', ''));
                                $session->set('student_loadslip_student_number', $normalizedSchoolId);
                                $session->set('student_loadslip_verified', true);
                                $this->persistLoadslipVerificationData($schoolId, $legacyCodes, $legacyRows, $normalizedSchoolId, $legacyPreviewPath);
                                $isVerified = $this->isLoadslipVerifiedForStudent($request, $schoolId);
                            }
                        }

                        if (!$isVerified) {
                            $error = 'Loadslip is not verified for this Student ID. Please import and verify your loadslip first.';
                        } elseif (!$this->qrLoadslipRowMatches($request, $schoolId, (string) $subject->getSubjectCode(), $finalSection !== '' ? $finalSection : null, $schedule !== '' ? $schedule : null, (string) ($subject->getSubjectName() ?? ''))) {
                            $error = 'Loadslip details do not match this QR evaluation (subject code, section, schedule, or description).';
                        } else {
                            $request->getSession()->set($verificationSessionKey, $student->getId());
                            $verifiedStudent = $student;
                        }
                    }
                }
            } elseif ($action === 'submit') {
                if (!$verifiedStudent) {
                    $error = 'Please verify your Student ID first.';
                } elseif (!in_array($privacyConsent, ['agree', 'disagree'], true)) {
                    $error = 'Please select Agree or Disagree for the Data Privacy Disclaimer.';
                } elseif (!$this->isLoadslipVerifiedForStudent($request, (string) $verifiedStudent->getSchoolId())) {
                    $error = 'Loadslip verification expired or missing. Please import and verify your loadslip again.';
                } elseif (!$this->qrLoadslipRowMatches($request, (string) $verifiedStudent->getSchoolId(), (string) $subject->getSubjectCode(), $finalSection !== '' ? $finalSection : null, $schedule !== '' ? $schedule : null, (string) ($subject->getSubjectName() ?? ''))) {
                    $error = 'Loadslip details no longer match this QR evaluation (subject code, section, schedule, or description).';
                }
            }

            if ($verifiedStudent && !$error && $action === 'submit') {
                $sectionToCheck = $finalSection !== '' ? $finalSection : null;
                if ($responseRepo->hasSubmitted($verifiedStudent->getId(), $eval->getId(), $faculty->getId(), $subject->getId(), $sectionToCheck)) {
                    $error = 'You have already submitted this evaluation.';
                } else {
                    // Save responses
                    $ratings = $request->request->all('ratings');
                    $comments = $request->request->all('comments');
                    $generalComment = trim($comments[0] ?? '');
                    $commentSaved = false;

                    foreach ($questions as $q) {
                        $rating = (int) ($ratings[$q->getId()] ?? 0);
                        if ($rating === 0) continue;

                        $response = new EvaluationResponse();
                        $response->setEvaluationPeriod($eval);
                        $response->setQuestion($q);
                        $response->setFaculty($faculty);
                        $response->setSubject($subject);
                        $response->setSection($finalSection !== '' ? $finalSection : null);
                        $response->setRating($rating);
                        // Attach the general comment to the first response
                        if (!$commentSaved && $generalComment !== '') {
                            $response->setComment($generalComment);
                            $commentSaved = true;
                        }
                        $response->setIsDraft(false);
                            $response->setEvaluator($verifiedStudent);
                        $em->persist($response);
                    }

                    $em->flush();

                    $this->audit->log(AuditLog::ACTION_SUBMIT_SET, 'EvaluationResponse', null,
                            'QR submission by ' . $verifiedStudent->getFullName() . ' for ' . $faculty->getFullName() . ' / ' . $subject->getSubjectCode());

                    $this->sendSubmissionConfirmationEmail(
                            $verifiedStudent->getEmail(),
                            $verifiedStudent->getFullName(),
                        $faculty->getFullName(),
                        (string) $subject->getSubjectCode(),
                        (string) $subject->getSubjectName(),
                        $finalSection !== '' ? $finalSection : null,
                    );

                    $success = true;
                }
            }
        }

        $qrLoadslipRows = [];
        if ($verifiedStudent) {
            $qrLoadslipRows = $this->getQrLoadslipMatchedRows(
                $request,
                (string) $verifiedStudent->getSchoolId(),
                (string) $subject->getSubjectCode(),
                $finalSection !== '' ? $finalSection : null,
                $schedule !== '' ? $schedule : null,
                (string) ($subject->getSubjectName() ?? '')
            );
        }

        return $this->render('evaluation/qr_form.html.twig', [
            'evaluation' => $eval,
            'subject' => $subject,
            'faculty' => $faculty,
            'section' => $finalSection,
            'schedule' => $schedule,
            'questions' => $questions,
            'categoryDescriptions' => $descRepo->findDescriptionsByType('SET'),
            'privacyDisclaimerText' => $descRepo->getDisclaimerText('SET'),
            'privacyDisclaimerHtml' => $descRepo->getDisclaimerHtml('SET'),
            'verifiedStudent' => $verifiedStudent,
            'qrVerified' => $verifiedStudent !== null,
            'qrLoadslipRows' => $qrLoadslipRows,
            'formValues' => [
                'school_id' => (string) $request->request->get('school_id', ''),
                'privacy_consent' => (string) $request->request->get('privacy_consent', ''),
            ],
            'error' => $error,
            'success' => $success,
        ]);
    }

    #[Route('/set', name: 'evaluation_set_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function setIndex(
        Request $request,
        CurriculumRepository $curriculumRepo,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        SubjectRepository $subjectRepo,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if ($user && method_exists($user, 'isStudent') && $user->isStudent() && $user->getSchoolId()) {
            $this->isLoadslipVerifiedForStudent($request, (string) $user->getSchoolId());
        }

        $openEvals = $evalRepo->findOpen();
        $curriculumCodeMap = $this->getCurriculumSubjectCodeMapForUser($user, $curriculumRepo);
        $loadslipCodes = (array) $request->getSession()->get('student_loadslip_codes', []);
        $loadslipRows = (array) $request->getSession()->get('student_loadslip_rows', []);

        $loadslipCodes = array_values(array_unique(array_filter(array_map(
            fn($code) => $this->normalizeSubjectCode((string) $code),
            $loadslipCodes
        ))));
        if (!empty($curriculumCodeMap)) {
            $loadslipCodes = array_values(array_filter(
                $loadslipCodes,
                fn(string $code): bool => isset($curriculumCodeMap[$code])
            ));
        }

        $filteredLoadslipRows = [];
        foreach ($loadslipRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowCode = $this->normalizeSubjectCode((string) ($row['code'] ?? ''));
            if (!empty($curriculumCodeMap) && $rowCode !== '' && !isset($curriculumCodeMap[$rowCode])) {
                continue;
            }

            if ($rowCode !== '') {
                $loadslipCodes[] = $rowCode;
            }

            $filteredLoadslipRows[] = $row;
        }
        $loadslipRows = $filteredLoadslipRows;
        $loadslipCodes = array_values(array_unique(array_filter($loadslipCodes)));

        $loadslipPreviewPath = $this->normalizeLoadslipPreviewPath((string) $request->getSession()->get('student_loadslip_preview_path', ''));
        if ($loadslipPreviewPath !== '' && !$this->loadslipPreviewPathExists($loadslipPreviewPath)) {
            $loadslipPreviewPath = '';
            $request->getSession()->remove('student_loadslip_preview_path');
        }
        $loadslipCodeMap = array_fill_keys(array_map(fn($code) => $this->normalizeSubjectCode((string) $code), $loadslipCodes), true);
        $canSeeOcrDebug = (bool) $this->getParameter('kernel.debug') || $this->isGranted('ROLE_ADMIN');
        $ocrDebugPreview = $canSeeOcrDebug ? $request->getSession()->get('student_loadslip_ocr_debug') : null;

        $studentYearLevel = $this->normalizeYearLevel($user->getYearLevel());

        // ── Build evaluation list ──
        $subjects = [];
        foreach ($openEvals as $eval) {
            if ($eval->getEvaluationType() !== 'SET') {
                continue;
            }

            // ── Targeting filters: department, college, year level ──
            if ($eval->getYearLevel() !== null) {
                $evalYL = $this->normalizeYearLevel($eval->getYearLevel());
                if ($evalYL !== $studentYearLevel) continue;
            }

            if ($eval->getDepartment() !== null && (
                $user->getDepartment() === null || $eval->getDepartment()->getId() !== $user->getDepartment()->getId()
            )) {
                continue;
            }

            if ($eval->getCollege() !== null && (
                $user->getDepartment() === null ||
                $user->getDepartment()->getCollegeName() !== $eval->getCollege()
            )) {
                continue;
            }

            // ── Resolve faculty from evaluation ──
            $faculty = null;
            $subject = null;

            if ($eval->getFaculty()) {
                $faculty = $userRepo->findOneByFullName($eval->getFaculty());
            }

            if ($eval->getSubject()) {
                $parts = explode(' — ', $eval->getSubject(), 2);
                $code = trim($parts[0]);
                $subject = $subjectRepo->findOneBy(['subjectCode' => $code]);
            }

            if (!$faculty || !$subject) {
                continue;
            }

            if (!empty($loadslipCodeMap)) {
                $subjectCode = $this->normalizeSubjectCode((string) $subject->getSubjectCode());
                if (!isset($loadslipCodeMap[$subjectCode])) {
                    continue;
                }
            }

            $submitted = $responseRepo->hasSubmitted(
                $user->getId(), $eval->getId(), $faculty->getId(), $subject->getId()
            );
            $drafts = $responseRepo->findDrafts($user->getId(), $eval->getId(), $faculty->getId(), $subject->getId());

            $subjects[] = [
                'evaluation' => $eval,
                'subject' => $subject,
                'faculty' => $faculty,
                'submitted' => $submitted,
                'hasDraft' => count($drafts) > 0,
            ];
        }

        return $this->render('evaluation/set_index.html.twig', [
            'subjects' => $subjects,
            'loadslipImportedCount' => count($loadslipCodeMap),
            'loadslipRows' => $loadslipRows,
            'loadslipPreviewPath' => $loadslipPreviewPath,
            'ocrDebugPreview' => $ocrDebugPreview,
            'canSeeOcrDebug' => $canSeeOcrDebug,
        ]);
    }

    #[Route('/set/loadslip/import', name: 'evaluation_set_loadslip_import', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function importSetLoadslip(Request $request, SubjectRepository $subjectRepo, CurriculumRepository $curriculumRepo, EvaluationPeriodRepository $evalRepo, AcademicYearRepository $ayRepo): Response
    {
        if (!$this->isCsrfTokenValid('import_set_loadslip', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid request token. Please try again.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        // Get logged-in student
        /** @var \App\Entity\User $student */
        $student = $this->getUser();
        if (!$student || !$student->getSchoolId()) {
            $this->addFlash('danger', 'Student ID not found in your profile. Please contact support.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        $reimporting = $this->isLoadslipVerifiedForStudent($request, (string) $student->getSchoolId());

        $file = $request->files->get('loadslip_file');
        if (!$file) {
            $this->addFlash('danger', 'Please select a loadslip image (JPG, JPEG, or PNG) to import.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($ext, ['jpeg', 'jpg', 'png'], true)) {
            $this->addFlash('danger', 'Unsupported file type. Please upload JPG, JPEG, or PNG.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        $path = (string) $file->getPathname();
        $blurMeta = null;
        $blurCheck = $this->detectLoadslipBlur($path, $blurMeta);
        if (is_array($blurCheck) && !(bool) ($blurCheck['isReadable'] ?? true)) {
            $blurFail = (bool) ($blurCheck['isBlurry'] ?? false);
            $contrastFail = (bool) ($blurCheck['isLowContrast'] ?? false);
            $reasonParts = [];
            if ($blurFail) {
                $reasonParts[] = sprintf(
                    'blur score %.1f (minimum %.1f)',
                    (float) ($blurCheck['score'] ?? 0.0),
                    (float) ($blurCheck['threshold'] ?? 0.0)
                );
            }
            if ($contrastFail) {
                $reasonParts[] = sprintf(
                    'contrast %.1f (minimum %.1f)',
                    (float) ($blurCheck['contrastScore'] ?? 0.0),
                    (float) ($blurCheck['contrastThreshold'] ?? 0.0)
                );
            }
            $reasonText = empty($reasonParts) ? 'image readability is too low' : implode(', ', $reasonParts);

            $this->removeStoredLoadslipPreview($request);
            $request->getSession()->remove('student_loadslip_preview_path');
            $request->getSession()->remove('student_loadslip_codes');
            $request->getSession()->remove('student_loadslip_rows');
            $request->getSession()->remove('student_loadslip_ocr_debug');
            $request->getSession()->remove('student_loadslip_student_number');
            $request->getSession()->remove('student_loadslip_verified');

            $this->addFlash('warning', 'Image is not readable for OCR (' . $reasonText . '). Please upload a clearer JPG or PNG loadslip photo.');

            return $this->redirectToRoute('evaluation_set_index');
        }

        $colorMeta = null;
        $colorCheck = $this->detectLoadslipColorProfile($path, $colorMeta);
        if (is_array($colorCheck) && !(bool) ($colorCheck['isColoredEnough'] ?? true)) {
            $this->removeStoredLoadslipPreview($request);
            $request->getSession()->remove('student_loadslip_preview_path');
            $request->getSession()->remove('student_loadslip_codes');
            $request->getSession()->remove('student_loadslip_rows');
            $request->getSession()->remove('student_loadslip_ocr_debug');
            $request->getSession()->remove('student_loadslip_student_number');
            $request->getSession()->remove('student_loadslip_verified');

            $this->addFlash('warning', 'Upload rejected: image does not match the required loadslip format shown in the instruction. Please upload a loadslip image similar to the instruction examples.');

            return $this->redirectToRoute('evaluation_set_index');
        }

        $ocrDebugData = null;
        $parsedRows = [];
        $expectedStudentNumber = $this->normalizeStudentNumber((string) $student->getSchoolId());
        $importData = $this->parseLoadslipImage($path, $ocrDebugData, $parsedRows, $expectedStudentNumber);

        $codes = $importData['codes'] ?? [];
        $importedStudentNumber = $this->normalizeStudentNumber((string) ($importData['studentNumber'] ?? ''));
        $formatMatched = (bool) ($importData['formatMatched'] ?? true);
        $formatReason = trim((string) ($importData['formatReason'] ?? ''));
        $importedSemester = $this->normalizeSemesterLabel((string) ($importData['semester'] ?? ''));
        $importedSchoolYear = $this->normalizeSchoolYearLabel((string) ($importData['schoolYear'] ?? ''));

        $activeSetPeriods = $evalRepo->findOpen('SET');
        if (empty($activeSetPeriods)) {
            $activeSetPeriods = $evalRepo->findActive('SET');
        }

        $currentAcademicYear = $ayRepo->findCurrent() ?? $ayRepo->findLatestBySequence();
        $expectedAcademicYear = $this->normalizeSchoolYearLabel((string) ($currentAcademicYear?->getYearLabel() ?? ''));
        $expectedAcademicSemester = $this->normalizeSemesterLabel((string) ($currentAcademicYear?->getSemester() ?? ''));

        $expectedSemesterMap = [];
        $expectedSchoolYearMap = [];
        foreach ($activeSetPeriods as $activeSetPeriod) {
            $periodSemester = $this->normalizeSemesterLabel((string) ($activeSetPeriod->getSemester() ?? ''));
            if ($periodSemester !== '') {
                $expectedSemesterMap[$periodSemester] = true;
            }

            $periodSchoolYear = $this->normalizeSchoolYearLabel((string) ($activeSetPeriod->getSchoolYear() ?? ''));
            if ($periodSchoolYear !== '') {
                $expectedSchoolYearMap[$periodSchoolYear] = true;
            }
        }
        if ($expectedAcademicSemester !== '') {
            $expectedSemesterMap[$expectedAcademicSemester] = true;
        }
        if ($expectedAcademicYear !== '') {
            $expectedSchoolYearMap[$expectedAcademicYear] = true;
        }
        $expectedSemesters = array_keys($expectedSemesterMap);
        $expectedSchoolYears = array_keys($expectedSchoolYearMap);

        if (is_array($ocrDebugData)) {
            $ocrDebugData['importTrace'] = [
                'expectedStudentNumber' => $expectedStudentNumber,
                'importedStudentNumber' => $importedStudentNumber,
                'formatMatched' => $formatMatched,
                'formatReason' => $formatReason,
                'importedSemester' => $importedSemester,
                'importedSchoolYear' => $importedSchoolYear,
                'expectedSemesters' => $expectedSemesters,
                'expectedSchoolYears' => $expectedSchoolYears,
                'expectedAcademicYear' => $expectedAcademicYear,
                'expectedAcademicSemester' => $expectedAcademicSemester,
                'detectedCodes' => array_values(array_map(fn($code) => $this->normalizeSubjectCode((string) $code), $codes)),
                'detectedRowCount' => count($parsedRows),
                'blurCheck' => is_array($blurCheck) ? $blurCheck : null,
                'colorCheck' => is_array($colorCheck) ? $colorCheck : null,
            ];
        }

        if (!$formatMatched) {
            $this->removeStoredLoadslipPreview($request);
            $request->getSession()->remove('student_loadslip_preview_path');
            $request->getSession()->remove('student_loadslip_codes');
            $request->getSession()->remove('student_loadslip_rows');
            $request->getSession()->remove('student_loadslip_ocr_debug');
            $request->getSession()->remove('student_loadslip_student_number');
            $request->getSession()->remove('student_loadslip_verified');

            $this->addFlash('warning', 'Upload rejected: image does not match the required loadslip format shown in the instruction. Please upload a loadslip image similar to the instruction examples.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        if (!empty($expectedSemesters) && ($importedSemester === '' || !isset($expectedSemesterMap[$importedSemester]))) {
            $this->removeStoredLoadslipPreview($request);
            $request->getSession()->remove('student_loadslip_preview_path');
            $request->getSession()->remove('student_loadslip_codes');
            $request->getSession()->remove('student_loadslip_rows');
            $request->getSession()->remove('student_loadslip_ocr_debug');
            $request->getSession()->remove('student_loadslip_student_number');
            $request->getSession()->remove('student_loadslip_verified');

            $detectedSemesterLabel = $importedSemester !== '' ? $importedSemester : 'UNKNOWN';
            $this->addFlash('warning', 'Upload rejected: loadslip semester mismatch. Detected ' . $detectedSemesterLabel . ' but active evaluation semester is ' . implode('/', $expectedSemesters) . '.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        if (!empty($expectedSchoolYears) && ($importedSchoolYear === '' || !isset($expectedSchoolYearMap[$importedSchoolYear]))) {
            $this->removeStoredLoadslipPreview($request);
            $request->getSession()->remove('student_loadslip_preview_path');
            $request->getSession()->remove('student_loadslip_codes');
            $request->getSession()->remove('student_loadslip_rows');
            $request->getSession()->remove('student_loadslip_ocr_debug');
            $request->getSession()->remove('student_loadslip_student_number');
            $request->getSession()->remove('student_loadslip_verified');

            $detectedSchoolYearLabel = $importedSchoolYear !== '' ? $importedSchoolYear : 'UNKNOWN';
            $this->addFlash('warning', 'Upload rejected: loadslip school year mismatch. Detected ' . $detectedSchoolYearLabel . ' but active academic year is ' . implode('/', $expectedSchoolYears) . '.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        // Verify student ID matches
        if ($importedStudentNumber === '') {
            $this->removeStoredLoadslipPreview($request);
            $request->getSession()->remove('student_loadslip_preview_path');
            $request->getSession()->remove('student_loadslip_codes');
            $request->getSession()->remove('student_loadslip_rows');
            $request->getSession()->remove('student_loadslip_ocr_debug');
            $request->getSession()->remove('student_loadslip_student_number');
            $request->getSession()->remove('student_loadslip_verified');
            $this->addFlash('warning', 'Could not extract Student ID from loadslip. Please verify the document is valid.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        if ($importedStudentNumber !== $expectedStudentNumber) {
            $this->removeStoredLoadslipPreview($request);
            $request->getSession()->remove('student_loadslip_preview_path');
            $request->getSession()->remove('student_loadslip_codes');
            $request->getSession()->remove('student_loadslip_rows');
            $request->getSession()->remove('student_loadslip_ocr_debug');
            $request->getSession()->remove('student_loadslip_student_number');
            $request->getSession()->remove('student_loadslip_verified');
            $this->addFlash('danger', 'Student ID mismatch. Please upload the correct loadslip.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        // Student ID matched: now update preview state.
        $previewPath = null;
        if (in_array($ext, ['jpeg', 'jpg', 'png'], true)) {
            $previewPath = $this->persistLoadslipPreviewImage($request, $file, $ext);
            if ($previewPath !== null) {
                $request->getSession()->set('student_loadslip_preview_path', $this->normalizeLoadslipPreviewPath($previewPath));
            }
        }

        $canStoreOcrDebug = (bool) $this->getParameter('kernel.debug') || $this->isGranted('ROLE_ADMIN');
        if ($canStoreOcrDebug && in_array($ext, ['jpeg', 'jpg', 'png'], true) && is_array($ocrDebugData)) {
            $request->getSession()->set('student_loadslip_ocr_debug', $ocrDebugData);
        }

        // Keep only codes that exist in master subjects to avoid OCR noise.
        if (!empty($codes)) {
            $curriculumCodeMap = $this->getCurriculumSubjectCodeMapForUser($student, $curriculumRepo);
            $knownCodeByCompact = $this->buildKnownSubjectCodeByCompact($subjectRepo, $curriculumCodeMap);

            if (is_array($ocrDebugData)) {
                $ocrDebugData['importTrace']['curriculumCodeCount'] = count($curriculumCodeMap);
                $ocrDebugData['importTrace']['curriculumFilterApplied'] = !empty($curriculumCodeMap);
            }

            $codeResolutionTrace = [];
            $resolvedCodeMap = [];
            foreach ($codes as $candidateCode) {
                $normalizedCandidateCode = $this->normalizeSubjectCode((string) $candidateCode);
                $resolvedCode = $this->resolveToKnownSubjectCode((string) $candidateCode, $knownCodeByCompact);
                if ($resolvedCode === '') {
                    $codeResolutionTrace[] = [
                        'input' => $normalizedCandidateCode,
                        'resolved' => '',
                        'status' => 'dropped_unknown_code',
                    ];
                    continue;
                }

                if (!empty($curriculumCodeMap) && !isset($curriculumCodeMap[$resolvedCode])) {
                    $codeResolutionTrace[] = [
                        'input' => $normalizedCandidateCode,
                        'resolved' => $resolvedCode,
                        'status' => 'dropped_not_in_curriculum',
                    ];
                    continue;
                }

                $codeResolutionTrace[] = [
                    'input' => $normalizedCandidateCode,
                    'resolved' => $resolvedCode,
                    'status' => $resolvedCode === $normalizedCandidateCode ? 'exact' : 'fuzzy',
                ];
                $resolvedCodeMap[$resolvedCode] = true;
            }
            $codes = array_keys($resolvedCodeMap);

            $rowResolutionTrace = [];
            $resolvedRows = [];
            $resolvedRowKeys = [];
            foreach ($parsedRows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $inputCode = $this->normalizeSubjectCode((string) ($row['code'] ?? ''));
                $inputSection = trim((string) ($row['section'] ?? ''));
                $inputDescription = trim((string) ($row['description'] ?? ''));
                $inputSchedule = trim((string) ($row['schedule'] ?? ''));
                $inputUnits = trim((string) ($row['units'] ?? ''));
                $resolvedCode = $this->resolveToKnownSubjectCode((string) ($row['code'] ?? ''), $knownCodeByCompact);
                $resolvedCode = $this->applyLoadslipSubjectCodeDescriptionHeuristic($resolvedCode, $inputDescription);
                if ($resolvedCode === '') {
                    $rowResolutionTrace[] = [
                        'inputCode' => $inputCode,
                        'resolvedCode' => '',
                        'section' => $inputSection,
                        'description' => $inputDescription,
                        'schedule' => $inputSchedule,
                        'units' => $inputUnits,
                        'status' => 'dropped_unknown_code',
                    ];
                    continue;
                }

                if (!empty($curriculumCodeMap) && !isset($curriculumCodeMap[$resolvedCode])) {
                    $rowResolutionTrace[] = [
                        'inputCode' => $inputCode,
                        'resolvedCode' => $resolvedCode,
                        'section' => $inputSection,
                        'description' => $inputDescription,
                        'schedule' => $inputSchedule,
                        'units' => $inputUnits,
                        'status' => 'dropped_not_in_curriculum',
                    ];
                    continue;
                }

                $resolvedRow = [
                    'code' => $resolvedCode,
                    'section' => trim((string) ($row['section'] ?? '')),
                    'description' => $this->normalizeLoadslipDescription(
                        (string) ($row['description'] ?? ''),
                        $resolvedCode
                    ),
                    'schedule' => trim((string) ($row['schedule'] ?? '')),
                    'units' => trim((string) ($row['units'] ?? '')),
                ];

                $key = $resolvedRow['code']
                    . '|' . $this->normalizeSectionValue($resolvedRow['section'])
                    . '|' . $this->normalizeScheduleValue($resolvedRow['schedule']);
                if (isset($resolvedRowKeys[$key])) {
                    $rowResolutionTrace[] = [
                        'inputCode' => $inputCode,
                        'resolvedCode' => $resolvedCode,
                        'section' => $resolvedRow['section'],
                        'description' => $resolvedRow['description'],
                        'schedule' => $resolvedRow['schedule'],
                        'units' => $resolvedRow['units'],
                        'status' => 'dropped_duplicate',
                    ];
                    continue;
                }

                $resolvedRowKeys[$key] = true;
                $resolvedRows[] = $resolvedRow;
                $rowResolutionTrace[] = [
                    'inputCode' => $inputCode,
                    'resolvedCode' => $resolvedCode,
                    'section' => $resolvedRow['section'],
                    'description' => $resolvedRow['description'],
                    'schedule' => $resolvedRow['schedule'],
                    'units' => $resolvedRow['units'],
                    'status' => $resolvedCode === $inputCode ? 'exact' : 'fuzzy',
                ];
            }
            $parsedRows = $resolvedRows;

            $readableRowCount = 0;
            foreach ($parsedRows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $rowCode = $this->normalizeSubjectCode((string) ($row['code'] ?? ''));
                $rowSchedule = trim((string) ($row['schedule'] ?? ''));
                if ($rowCode !== '' && $rowSchedule !== '') {
                    $readableRowCount++;
                }
            }

            if ($canStoreOcrDebug && is_array($ocrDebugData)) {
                $ocrDebugData['importTrace']['readableRowCount'] = $readableRowCount;
            }

            if ($readableRowCount === 0) {
                $this->removeStoredLoadslipPreview($request);
                $request->getSession()->remove('student_loadslip_preview_path');
                $request->getSession()->remove('student_loadslip_codes');
                $request->getSession()->remove('student_loadslip_rows');
                $request->getSession()->remove('student_loadslip_student_number');
                $request->getSession()->remove('student_loadslip_verified');

                if ($canStoreOcrDebug && is_array($ocrDebugData)) {
                    $request->getSession()->set('student_loadslip_ocr_debug', $ocrDebugData);
                }

                $this->addFlash('warning', 'Image text is not readable enough for OCR. Import was stopped. Please upload a clearer JPG or PNG loadslip.');
                return $this->redirectToRoute('evaluation_set_index');
            }

            // Ensure every detected valid code has at least one row entry.
            $rowCodeMap = [];
            foreach ($parsedRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowCode = $this->normalizeSubjectCode((string) ($row['code'] ?? ''));
                if ($rowCode !== '') {
                    $rowCodeMap[$rowCode] = true;
                }
            }

            if (!empty($rowCodeMap)) {
                $codes = array_keys($rowCodeMap);
            }

            foreach ($codes as $code) {
                $normalizedCode = $this->normalizeSubjectCode((string) $code);
                if ($normalizedCode === '' || isset($rowCodeMap[$normalizedCode])) {
                    continue;
                }
                $parsedRows[] = [
                    'code' => $normalizedCode,
                    'section' => '',
                    'description' => '',
                    'schedule' => '',
                    'units' => '',
                ];
                $rowCodeMap[$normalizedCode] = true;
            }

            if ($canStoreOcrDebug && in_array($ext, ['jpeg', 'jpg', 'png'], true) && is_array($ocrDebugData)) {
                $ocrDebugData['validCodes'] = array_values($codes);
                $ocrDebugData['importTrace']['codeResolution'] = $codeResolutionTrace;
                $ocrDebugData['importTrace']['rowResolution'] = $rowResolutionTrace;
                $ocrDebugData['importTrace']['validRowCount'] = count($parsedRows);
                $request->getSession()->set('student_loadslip_ocr_debug', $ocrDebugData);
            }
        }

        if (empty($codes)) {
            $this->removeStoredLoadslipPreview($request);
            $request->getSession()->remove('student_loadslip_preview_path');
            $request->getSession()->remove('student_loadslip_codes');
            $request->getSession()->remove('student_loadslip_rows');
            $request->getSession()->remove('student_loadslip_student_number');
            $request->getSession()->remove('student_loadslip_verified');
            $this->addFlash('warning', 'No valid subject codes were found from the uploaded image. Please use a clearer image.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        $request->getSession()->set('student_loadslip_codes', $codes);
        $request->getSession()->set('student_loadslip_rows', $parsedRows);
        $request->getSession()->set('student_loadslip_student_number', (string) $importedStudentNumber);
        $request->getSession()->set('student_loadslip_verified', true);
        $this->persistLoadslipVerificationData((string) $student->getSchoolId(), $codes, $parsedRows, (string) $importedStudentNumber, $previewPath, $importedSchoolYear, $importedSemester);
        $this->addFlash('success', $reimporting
            ? 'Loadslip re-imported successfully. Verification data has been refreshed.'
            : 'Loadslip imported successfully.');

        return $this->redirectToRoute('evaluation_set_index');
    }

    #[Route('/set/loadslip/clear', name: 'evaluation_set_loadslip_clear', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function clearSetLoadslip(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('clear_set_loadslip', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid request token. Please try again.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        $request->getSession()->remove('student_loadslip_codes');
        $request->getSession()->remove('student_loadslip_rows');
        $request->getSession()->remove('student_loadslip_student_number');
        $request->getSession()->remove('student_loadslip_verified');
        /** @var \App\Entity\User|null $student */
        $student = $this->getUser();
        if ($student && $student->getSchoolId()) {
            $this->clearLoadslipVerificationData((string) $student->getSchoolId());
        }
        $this->removeStoredLoadslipPreview($request);
        $request->getSession()->remove('student_loadslip_preview_path');
        $request->getSession()->remove('student_loadslip_ocr_debug');
        $this->addFlash('info', 'Imported loadslip filter cleared.');

        return $this->redirectToRoute('evaluation_set_index');
    }

    #[Route('/set/{evalId}/{subjectId}', name: 'evaluation_set_form', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function setForm(
        int $evalId,
        int $subjectId,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        SubjectRepository $subjectRepo,
        QuestionRepository $questionRepo,
        EvaluationResponseRepository $responseRepo,
        QuestionCategoryDescriptionRepository $descRepo,
        EntityManagerInterface $em,
        UserRepository $userRepo,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $eval = $evalRepo->find($evalId);
        $subject = $subjectRepo->find($subjectId);

        if (!$eval || !$subject || !$eval->isOpen() || $eval->getEvaluationType() !== 'SET') {
            $this->addFlash('danger', 'Invalid evaluation or evaluation is closed.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        if (!$this->isLoadslipVerifiedForStudent($request, (string) $user->getSchoolId())) {
            $this->addFlash('warning', 'Please import and verify your loadslip first before accessing the evaluation form.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        $faculty = $subject->getFaculty();
        if (!$faculty && $eval->getFaculty()) {
            $faculty = $userRepo->findOneByFullName($eval->getFaculty());
        }
        if (!$faculty) {
            $this->addFlash('danger', 'No faculty assigned to this subject.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        // Check if already submitted (non-draft)
        if ($responseRepo->hasSubmitted($user->getId(), $evalId, $faculty->getId(), $subjectId)) {
            $this->addFlash('warning', 'You have already submitted this evaluation.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        $questions = $questionRepo->findByType('SET');

        // Load existing drafts
        $drafts = $responseRepo->findDrafts($user->getId(), $evalId, $faculty->getId(), $subjectId);
        $draftMap = [];
        foreach ($drafts as $draft) {
            $draftMap[$draft->getQuestion()->getId()] = $draft;
        }

        if ($request->isMethod('POST')) {
            $isDraft = $request->request->get('_action') === 'save_draft';
            $ratings = $request->request->all('ratings');
            $comments = $request->request->all('comments');
            $generalComment = trim($comments[0] ?? '');
            $commentSaved = false;

            // Remove old drafts
            foreach ($drafts as $draft) {
                $em->remove($draft);
            }

            foreach ($questions as $q) {
                $rating = (int) ($ratings[$q->getId()] ?? 0);
                if ($rating === 0 && !$isDraft) {
                    continue; // Skip unrated for final submission
                }

                $response = new EvaluationResponse();
                $response->setEvaluationPeriod($eval);
                $response->setQuestion($q);
                $response->setFaculty($faculty);
                $response->setSubject($subject);
                $response->setRating($rating);
                // Attach the general comment to the first response
                if (!$commentSaved && $generalComment !== '') {
                    $response->setComment($generalComment);
                    $commentSaved = true;
                }
                $response->setIsDraft($isDraft);

                // Always store evaluator for submission tracking;
                // anonymity is enforced at the reporting layer
                $response->setEvaluator($user);

                $em->persist($response);
            }

            $em->flush();

            if ($isDraft) {
                $this->audit->log(AuditLog::ACTION_SAVE_DRAFT, 'EvaluationResponse', null,
                    'Saved draft SET for ' . $faculty->getFullName() . ' / ' . $subject->getSubjectCode());
                $this->addFlash('info', 'Draft saved. You can continue later.');
            } else {
                $this->audit->log(AuditLog::ACTION_SUBMIT_SET, 'EvaluationResponse', null,
                    'Submitted SET for ' . $faculty->getFullName() . ' / ' . $subject->getSubjectCode());
                $this->sendSubmissionConfirmationEmail(
                    $user->getEmail(),
                    $user->getFullName(),
                    $faculty->getFullName(),
                    (string) $subject->getSubjectCode(),
                    (string) $subject->getSubjectName(),
                );
                $this->addFlash('success', 'Evaluation submitted successfully. Thank you!');
            }

            return $this->redirectToRoute('evaluation_set_index');
        }

        return $this->render('evaluation/set_form.html.twig', [
            'evaluation' => $eval,
            'subject' => $subject,
            'faculty' => $faculty,
            'questions' => $questions,
            'draftMap' => $draftMap,
            'categoryDescriptions' => $descRepo->findDescriptionsByType('SET'),
        ]);
    }

    // ════════════════════════════════════════════════
    //  SET — Evaluation History
    // ════════════════════════════════════════════════

    #[Route('/history', name: 'evaluation_history', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function history(
        EvaluationResponseRepository $responseRepo,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $history = $responseRepo->getStudentHistory($user->getId());

        return $this->render('evaluation/history.html.twig', [
            'history' => $history,
        ]);
    }

    private function sendSubmissionConfirmationEmail(
        ?string $toEmail,
        string $studentName,
        string $facultyName,
        string $subjectCode,
        string $subjectName,
        ?string $section = null,
    ): void {
        $toEmail = strtolower(trim((string) $toEmail));
        if ($toEmail === '') {
            return;
        }

        $sectionLine = $section ? "Section: {$section}\n" : '';
        $submittedAt = (new \DateTimeImmutable())->format('F d, Y h:i A');

        $message = (new Email())
            ->from(new Address('no-reply@setsef.local', 'SET-SEF Evaluation'))
            ->to($toEmail)
            ->subject('Evaluation Submission Confirmation')
            ->text(
                "Hello {$studentName},\n\n"
                . "Your evaluation has been submitted successfully.\n\n"
                . "Faculty: {$facultyName}\n"
                . "Subject: {$subjectCode} - {$subjectName}\n"
                . $sectionLine
                . "Submitted: {$submittedAt}\n\n"
                . "Thank you for participating in the SET-SEF evaluation process."
            );

        try {
            $this->mailer->send($message);
        } catch (\Throwable) {
            // Do not block evaluation submission if email transport is unavailable.
        }
    }
}
