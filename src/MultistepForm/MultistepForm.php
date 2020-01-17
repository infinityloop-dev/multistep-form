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
    private array $defaults = [];
    private bool $editing = false;
    private bool $stepSave = false;
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
        $this->template->setFile(__DIR__ . '/MultistepForm.latte');
        $this->template->render();
    }

    public function setFactories(array $factories) : self
    {
        $this->factories = $factories;

        return $this;
    }

    public function addFactory($factory) : self
    {
        $this->factories[] = $factory;

        return $this;
    }

    public function setDefaults(array $defaultValues) : self
    {
        $this->defaults = $defaultValues;

        return $this;
    }

    public function setEditing(bool $editing = true) : self
    {
        $this->editing = $editing;

        return $this;
    }

    public function setStepSave(bool $stepSave = true) : self
    {
        $this->stepSave = $stepSave;

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
        return $this->editing;
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
    }

    /**
     * @internal
     */
    public function loadState(array $params): void
    {
        $this->currentStep = (int) ($params['step'] ?? 1);
    }

    protected function createComponentForm() : \Nette\Forms\Form
    {
        if (\count($this->factories) < $this->currentStep) {
            throw new \Nette\InvalidStateException('No factory given');
        }

        $factory = $this->factories[$this->currentStep - 1];

        if (\is_object($factory) && \method_exists($factory, 'create')) {
            $step = $factory->create();
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

        /*if ($this->isEdit()) {
            $form->addSubmit('quicksave', 'Uložit');
        }*/

        $next->onClick[] = function (\Nette\Forms\Controls\SubmitButton $button) : void {
            $form = $button->getForm();
            \assert($form instanceof \Nette\Forms\Form);
            $this->onNext($form, $form->getValues('array'));
        };

        $previous->setValidationScope([]);
        $previous->onClick[] = function (\Nette\Forms\Controls\SubmitButton $button) : void {
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
        $section->setExpiration('+ 20 minutes');

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
