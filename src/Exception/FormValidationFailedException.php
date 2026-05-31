<?php

namespace AzYouness\RequestToFormBundle\Exception;

use Symfony\Component\Form\FormInterface;

final class FormValidationFailedException extends \RuntimeException
{
    /**
     * @param FormInterface<mixed> $form
     */
    public function __construct(
        private readonly FormInterface $form,
    ) {
        parent::__construct('Form validation failed.');
    }

    /**
     * @return FormInterface<mixed>
     */
    public function getForm(): FormInterface
    {
        return $this->form;
    }
}
