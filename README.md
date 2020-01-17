# Multistep form

:wrench: Component for Nette framwork which helps with creation of multistep forms.

## Introduction

## Installation

Install package using composer

```
composer require infinityloop-dev/multistep-form
```

## Dependencies

- [nette/application](https://github.com/nette/application)
- [nette/http](https://github.com/nette/http)
- [nette/forms](https://github.com/nette/forms)


## How to use

- Register `\Infinityloop\MultistepForm\IMultiStepFormFactory` as service in cofiguration file.
- Inject it into component/presenter where you wish to use multi step form, 
    - write createComponent method and use macro {control} in template file

### Example createComponent method

```
protected function createComponentMultistepForm() : \Infinityloop\MultistepForm\MultistepForm
{
    $multistepForm = $this->multistepFormFactory->create()
        ->setDefaults(['action' => \App\Enum\EAction::ACTION2])
        ->setSuccessCallback(function(array $values) {
            $this->model->save($values);
        });

    // first step
    $multistepForm->addFactory(function() : \Nette\Forms\Form {
        $form = new \Nette\Application\UI\Form();
        $form->addProtection();
        $form->setTranslator($this->translator);

        $form->addSelect('action', 'Akce', \App\Enum\EAction::ENUM)
            ->setRequired();

        return $form;
    }, __DIR__ . '/step1.latte');

    // second step
    $multistepForm->addFactory(function(array $previousValues) : \Nette\Forms\Form {
        $form = new \Nette\Application\UI\Form();
        $form->addProtection();
        $form->setTranslator($this->translator);

        if (\in_array($previousValues['action'], [\App\Enum\EAction::ACTION1, \App\Enum\EAction::ACTION2], true)) {
            $form->addAjaxSelect('action_1or2', 'Action 1 or 2')
                ->setRequired();
        } else {
            $form->addText('action_xyz', 'Action Xyz')
                ->setRequired();
        }
    });

    return $multistepForm;
}
```
