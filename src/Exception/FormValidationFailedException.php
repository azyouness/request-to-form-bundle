<?php

namespace AzYouness\RequestToFormBundle\Exception;

use Symfony\Component\Form\FormInterface;

final class FormValidationFailedException extends \RuntimeException
{
    public function __construct(
        private readonly FormInterface $form,
    ) {
        parent::__construct('Form validation failed.');
    }

    public function getForm(): FormInterface
    {
        return $this->form;
    }
}
