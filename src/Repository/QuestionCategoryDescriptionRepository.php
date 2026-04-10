<?php

namespace App\Repository;

use App\Entity\QuestionCategoryDescription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuestionCategoryDescription>
 */
class QuestionCategoryDescriptionRepository extends ServiceEntityRepository
{
    public const DISCLAIMER_CATEGORY = '__DATA_PRIVACY_DISCLAIMER__';
    public const SYSTEM_ANNOUNCEMENT_TYPE = 'GLOBAL';
    public const SYSTEM_ANNOUNCEMENT_TITLE_CATEGORY = '__SYSTEM_ANNOUNCEMENT_TITLE__';
    public const SYSTEM_ANNOUNCEMENT_BODY_CATEGORY = '__SYSTEM_ANNOUNCEMENT_BODY__';
    public const SYSTEM_ANNOUNCEMENT_META_CATEGORY = '__SYSTEM_ANNOUNCEMENT_META__';

    public const DEFAULT_DISCLAIMER_TEXT = "In compliance with the Data Privacy Act of 2012 (Republic Act No. 10173), Negros Oriental State University (NORSU) is committed to protecting the privacy and confidentiality of all personal information collected during the conduct of the Student Evaluation for Teachers (SET).\n\nAll data and responses gathered through this evaluation will be used solely for academic quality assurance and faculty performance improvement purposes. The information will be treated with strict confidentiality and will only be accessed by authorized personnel of the Quality Assurance Management Center (QUAMC) and related offices for evaluation, analysis, and reporting.\n\nPersonal identifiers, if any, will not be disclosed or used to affect student standing. Participation in this evaluation is voluntary, and submission of responses implies consent to the collection and processing of the provided information under the terms stated above.";

    public const DEFAULT_SYSTEM_ANNOUNCEMENT_TITLE = 'New evaluation schedules and updates are now available.';
    public const DEFAULT_SYSTEM_ANNOUNCEMENT_BODY = 'Please check your evaluation period, assigned subjects, and reminders before submitting. All notices will be posted here for students, faculty, and staff.';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestionCategoryDescription::class);
    }

    public function getDisclaimerText(string $evaluationType = 'SET'): string
    {
        $entity = $this->findOneBy([
            'category' => self::DISCLAIMER_CATEGORY,
            'evaluationType' => $evaluationType,
        ]);

        $text = trim((string) ($entity?->getDescription() ?? ''));
        return $text !== '' ? $text : self::DEFAULT_DISCLAIMER_TEXT;
    }

    public function getDisclaimerHtml(string $evaluationType = 'SET'): string
    {
        $text = $this->getDisclaimerText($evaluationType);
        $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safe = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe) ?? $safe;
        return nl2br($safe);
    }

    public function getSystemAnnouncementTitle(): string
    {
        $entity = $this->findOneBy([
            'category' => self::SYSTEM_ANNOUNCEMENT_TITLE_CATEGORY,
            'evaluationType' => self::SYSTEM_ANNOUNCEMENT_TYPE,
        ]);

        $title = trim((string) ($entity?->getDescription() ?? ''));
        return $title !== '' ? $title : self::DEFAULT_SYSTEM_ANNOUNCEMENT_TITLE;
    }

    public function getSystemAnnouncementBody(): string
    {
        $entity = $this->findOneBy([
            'category' => self::SYSTEM_ANNOUNCEMENT_BODY_CATEGORY,
            'evaluationType' => self::SYSTEM_ANNOUNCEMENT_TYPE,
        ]);

        $body = trim((string) ($entity?->getDescription() ?? ''));
        return $body !== '' ? $body : self::DEFAULT_SYSTEM_ANNOUNCEMENT_BODY;
    }

    public function getSystemAnnouncementBodyHtml(): string
    {
        $body = $this->getSystemAnnouncementBody();
        $safe = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safe = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe) ?? $safe;
        return nl2br($safe);
    }

    /**
     * @return array{updatedBy: string, updatedAt: string}
     */
    public function getSystemAnnouncementMeta(): array
    {
        $entity = $this->findOneBy([
            'category' => self::SYSTEM_ANNOUNCEMENT_META_CATEGORY,
            'evaluationType' => self::SYSTEM_ANNOUNCEMENT_TYPE,
        ]);

        $raw = trim((string) ($entity?->getDescription() ?? ''));
        if ($raw === '') {
            return ['updatedBy' => '', 'updatedAt' => ''];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['updatedBy' => '', 'updatedAt' => ''];
        }

        return [
            'updatedBy' => (string) ($decoded['updatedBy'] ?? ''),
            'updatedAt' => (string) ($decoded['updatedAt'] ?? ''),
        ];
    }

    /**
     * Return descriptions indexed by category name for a given evaluation type.
     * @return array<string, string>
     */
    public function findDescriptionsByType(string $evaluationType): array
    {
        $rows = $this->createQueryBuilder('d')
            ->where('d.evaluationType = :type')
            ->setParameter('type', $evaluationType)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->getCategory()] = $row->getDescription();
        }
        return $map;
    }
}
