<?php

namespace App\Form;

use App\Entity\Course;
use App\Entity\Department;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'attr' => ['class' => 'form-control', 'placeholder' => 'First Name'],
                'label' => 'First Name',
            ])
            ->add('lastName', TextType::class, [
                'attr' => ['class' => 'form-control', 'placeholder' => 'Last Name'],
                'label' => 'Last Name',
            ])
            ->add('email', EmailType::class, [
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'Email Address'],
                'label' => 'Email',
            ])
            ->add('role', ChoiceType::class, [
                'mapped' => false,
                'choices' => [
                    'Student' => 'student',
                    'Faculty' => 'faculty',
                    'Staff' => 'staff',
                    'Superior' => 'superior',
                ],
                'attr' => ['class' => 'form-select'],
                'label' => 'Register as',
            ])
            ->add('schoolId', TextType::class, [
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'e.g. 2024-00123'],
                'label' => 'Student ID Number',
            ])
            ->add('yearLevel', ChoiceType::class, [
                'required' => false,
                'choices' => [
                    '1st Year' => '1st Year',
                    '2nd Year' => '2nd Year',
                    '3rd Year' => '3rd Year',
                    '4th Year' => '4th Year',
                    '5th Year' => '5th Year',
                ],
                'placeholder' => 'Select year level',
                'attr' => ['class' => 'form-select'],
                'label' => 'Year Level',
            ])
            ->add('course', EntityType::class, [
                'class' => Course::class,
                'choice_label' => 'courseName',
                'required' => false,
                'placeholder' => 'Select course',
                'attr' => ['class' => 'form-select'],
                'label' => 'Course',
            ])
            ->add('department', EntityType::class, [
                'class' => Department::class,
                'choice_label' => 'departmentName',
                'required' => false,
                'placeholder' => 'Select department',
                'attr' => ['class' => 'form-select'],
                'label' => 'Department',
            ])
            ->add('employmentStatus', ChoiceType::class, [
                'required' => false,
                'choices' => [
                    'Regular' => 'Regular',
                    'Part-Time' => 'Part-Time',
                    'Temporary' => 'Temporary',
                ],
                'placeholder' => 'Select employment status',
                'attr' => ['class' => 'form-select'],
                'label' => 'Employment Status',
            ])
            ->add('campus', ChoiceType::class, [
                'required' => false,
                'choices' => [
                    'Main Campus I' => 'Main Campus I',
                    'Main Campus II' => 'Main Campus II',
                    'Bais Campus I' => 'Bais Campus I',
                    'Bais Campus II' => 'Bais Campus II',
                    'Bayawan-Sta. Catalina Campus' => 'Bayawan-Sta. Catalina Campus',
                    'Guihulngan Campus' => 'Guihulngan Campus',
                    'Mabinay Campus' => 'Mabinay Campus',
                    'Siaton Campus' => 'Siaton Campus',
                ],
                'placeholder' => 'Select campus',
                'attr' => ['class' => 'form-select'],
                'label' => 'Campus',
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => [
                    'attr' => ['class' => 'form-control', 'placeholder' => 'Password', 'autocomplete' => 'new-password'],
                    'label' => 'Password',
                    'constraints' => [
                        new NotBlank(message: 'Please enter a password'),
                        new Length(
                            min: 8,
                            minMessage: 'Your password should be at least {{ limit }} characters',
                            max: 4096,
                        ),
                        new Regex(
                            pattern: '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
                            message: 'Password must contain at least one uppercase letter, one lowercase letter, and one number.',
                        ),
                    ],
                ],
                'second_options' => [
                    'attr' => ['class' => 'form-control', 'placeholder' => 'Repeat Password', 'autocomplete' => 'new-password'],
                    'label' => 'Repeat Password',
                ],
                'invalid_message' => 'The password fields must match.',
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'mapped' => false,
                'constraints' => [
                    new IsTrue(message: 'You must agree to the terms and data privacy policy.'),
                ],
                'label' => false,
            ])
            // Honeypot field — must remain empty (bots fill it automatically)
            ->add('website', TextType::class, [
                'mapped' => false,
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'tabindex' => '-1'],
                'label' => false,
            ])
            // Timestamp for timing-based bot detection
            ->add('_ts', HiddenType::class, [
                'mapped' => false,
                'data' => (string) time(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
