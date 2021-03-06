<?php

declare(strict_types = 1);

namespace Infinityloop\MultistepForm;

final class MultistepForm extends \Nette\Application\UI\Control
{
    private const FORM_IDENTIFIER = 'sub_session_id';

    private \Nette\Http\Session $session;
    private string $subsessionId;
    private int $currentStep;
    private array $factories = [];
    private array $templates = [];
    private array $defaults = [];
    private bool $buttonSubmitted = false;
    private $successCallback = null;

    public function __construct(
        \Nette\Http\Session $session,
        \Nette\Http\Request $httpRequest
    )
    {
        $this->session = $session;
        $this->subsessionId = $httpRequest->getPost(self::FORM_IDENTIFIER) ?? \Nette\Utils\Random::generate(10);
    }

    public function render() : void
    {
        if (\count($this->factories) < $this->currentStep ||
            \count($this->templates) < $this->currentStep
        ) {
            throw new \Nette\InvalidStateException('No factory given');
        }

        $this->template->customTemplate = $this->templates[$this->currentStep - 1];
        $this->template->setFile(__DIR__ . '/MultistepForm.latte');
        $this->template->render();
    }

    public function setFactories(array $factories) : self
    {
        $this->factories = $factories;

        return $this;
    }

    public function addFactory($factory, string $templatePath = null) : self
    {
        $this->factories[] = $factory;
        $this->templates[] = $templatePath;

        return $this;
    }

    public function setDefaults(array $defaultValues) : self
    {
        $this->defaults = $defaultValues;

        return $this;
    }

    public function setSuccessCallback(callable $successCallback) : self
    {
        $this->successCallback = $successCallback;

        return $this;
    }

    /**
     * Returns current step.
     */
    public function getCurrentStep() : int
    {
        return $this->currentStep;
    }

    /**
     * Is current step the first one?
     */
    public function isFirst() : bool
    {
        return $this->currentStep === 1;
    }

    /**
     * Is current step the last one?
     */
    public function isLast() : bool
    {
        return $this->currentStep === \count($this->factories);
    }

    /**
     * Is editing?
     */
    public function isEdit() : bool
    {
        return false;
    }

    /**
     * Returns maximum achieved step.
     */
    public function getMaxStep() : int
    {
        return \iterator_count($this->getSection()->getIterator());
    }

    /**
     * Returns all filled values.
     *
     * @return array
     */
    public function getValues() : array
    {
        $return = [];

        foreach ($this->getSection()->getIterator() as $saved) {
            $return += $saved;
        }

        return $return;
    }

    /**
     * @internal
     */
    public function saveState(array &$params): void
    {
        $params['step'] = $this->currentStep ?? 1;
        $params[self::FORM_IDENTIFIER] = $this->subsessionId;
    }

    /**
     * @internal
     */
    public function loadState(array $params): void
    {
        $this->currentStep = (int) ($params['step'] ?? 1);
        $this->subsessionId = $params[self::FORM_IDENTIFIER] ?? $this->subsessionId;
    }

    protected function createComponentForm() : \Nette\Forms\Form
    {
        $factory = $this->factories[$this->currentStep - 1];

        if (\is_object($factory) && \method_exists($factory, 'create')) {
            $step = $factory->create($this->getValues());
        } elseif (\is_callable($factory)) {
            $step = $factory($this->getValues());
        } else {
            throw new \Nette\InvalidStateException('Factory must implement create method or be callable itself.');
        }

        if ($step instanceof \Nette\Forms\Form) {
            $step->addHidden(self::FORM_IDENTIFIER, $this->subsessionId)
                ->setOmitted();

            $this->appendButtons($step);

            $step->monitor(self::class, static function (MultistepForm $multistepForm) use ($step) : void {
                $step->setDefaults($multistepForm->defaults + $multistepForm->getTempValues());
            });

            return $step;
        }

        throw new \Nette\InvalidStateException('Return value of factory must be instance of Step');
    }

    private function appendButtons(\Nette\Forms\Form $form) : \Nette\Forms\Form
    {
        $next = $form->addSubmit('next', 'Další');
        $previous = $form->addSubmit('previous', 'Předchozí');

        if ($this->isFirst()) {
            $previous->setDisabled();
        }

        if ($this->isEdit()) {
            $form->addSubmit('quicksave', 'Uložit');
        }

        $form->onSuccess[] = function (\Nette\Forms\Form $form) : void {
            if ($this->buttonSubmitted) {
                return;
            }

            $this->onNext($form, $form->getValues('array'));
        };

        $next->onClick[] = function (\Nette\Forms\Controls\SubmitButton $button) : void {
            $this->buttonSubmitted = true;
            $form = $button->getForm();
            \assert($form instanceof \Nette\Forms\Form);
            $this->onNext($form, $form->getValues('array'));
        };

        $previous->setValidationScope([]);
        $previous->onClick[] = function (\Nette\Forms\Controls\SubmitButton $button) : void {
            $this->buttonSubmitted = true;
            $form = $button->getForm();
            \assert($form instanceof \Nette\Forms\Form);
            $this->onPrevious($form, $form->getValues('array'));
        };

        return $form;
    }

    private function getSection() : \Nette\Http\SessionSection
    {
        $section = $this->session->getSection('multiStepForm-' . $this->subsessionId);
        $section->warnOnUndefined = true;
        $section->setExpiration('+ 14 days');

        return $section;
    }

    private function getTempValues() : array
    {
        $section = $this->getSection();
        return $section[(string) $this->currentStep] ?? [];
    }

    private function saveTempValues(array $values) : void
    {
        $section = $this->getSection();
        $section[(string) $this->currentStep] = $values;
    }

    private function onNext(\Nette\Forms\Form $step, array $submittedValues) : void
    {
        $this->saveTempValues($submittedValues);

        if ($this->isLast()) {
            \call_user_func($this->successCallback, $this->getValues());

            return;
        }

        ++$this->currentStep;
        $this->redraw();
    }

    private function onPrevious(\Nette\Forms\Form $step, array $submittedValues) : void
    {
        if ($this->isFirst()) {
            return;
        }

        $this->saveTempValues($submittedValues);

        --$this->currentStep;
        $this->redraw();
    }

    private function redraw() : void
    {
        $form = $this->getComponent('form');

        if ($form instanceof \Nette\ComponentModel\IComponent) {
            $this->removeComponent($form);
        }

        $this->redrawControl('snippet');
    }
}
