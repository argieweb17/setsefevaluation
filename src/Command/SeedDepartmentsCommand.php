<?php

namespace App\Command;

use App\Entity\Department;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:seed-departments', description: 'Seed default NORSU colleges and departments')]
class SeedDepartmentsCommand extends Command
{
    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $data = [
            'College of Agriculture and Forestry' => [
                'Bachelor of Science in Agriculture',
                'Bachelor of Science in Forestry',
            ],
            'College of Arts and Sciences' => [
                'Bachelor of Arts in English Language',
                'Bachelor of Arts in Political Science',
                'Bachelor of Science in Biology',
                'Bachelor of Science in Chemistry',
                'Bachelor of Science in Mathematics',
                'Bachelor of Science in Environmental Science',
                'Bachelor of Science in Psychology',
                'Bachelor of Science in Computer Science',
                'Bachelor of Science in Information Technology',
            ],
            'College of Business Administration' => [
                'Bachelor of Science in Business Administration',
                'Bachelor of Science in Accountancy',
                'Bachelor of Science in Hospitality Management',
                'Bachelor of Science in Tourism Management',
            ],
            'College of Criminal Justice Education' => [
                'Bachelor of Science in Criminology',
            ],
            'College of Education' => [
                'Bachelor of Elementary Education',
                'Bachelor of Secondary Education',
                'Bachelor of Physical Education',
                'Bachelor of Early Childhood Education',
                'Bachelor of Special Needs Education',
            ],
            'College of Engineering and Architecture' => [
                'Bachelor of Science in Civil Engineering',
                'Bachelor of Science in Electrical Engineering',
                'Bachelor of Science in Mechanical Engineering',
                'Bachelor of Science in Computer Engineering',
                'Bachelor of Science in Architecture',
            ],
            'College of Industrial Technology' => [
                'Bachelor of Science in Industrial Technology',
                'Bachelor of Technical-Vocational Teacher Education',
      
            ],
            'College of Nursing and Allied Health Sciences' => [
                'Bachelor of Science in Nursing',
                'Bachelor of Science in Midwifery',
            ],
            'College of Law' => [
                'Juris Doctor',
            ],
            'Graduate School' => [
                'Master of Arts in Education',
                'Master of Science in Agriculture',
                'Doctor of Philosophy',
            ],
        ];

        $repo = $this->em->getRepository(Department::class);
        $created = 0;

        foreach ($data as $college => $departments) {
            foreach ($departments as $deptName) {
                $existing = $repo->findOneBy([
                    'departmentName' => $deptName,
                    'collegeName' => $college,
                ]);
                if ($existing) {
                    $io->text("  Skipped (exists): {$college} → {$deptName}");
                    continue;
                }
                $dept = new Department();
                $dept->setDepartmentName($deptName);
                $dept->setCollegeName($college);
                $this->em->persist($dept);
                $created++;
                $io->text("  Created: {$college} → {$deptName}");
            }
        }

        $this->em->flush();
        $io->success("Seeded {$created} departments.");

        return Command::SUCCESS;
    }
}
