<?php
namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\Extension\Core\ChoiceList\ChoiceList;

class AnalyzeFormType extends AbstractType
{

    /*
     * (non-PHPdoc)
     * @see \Symfony\Component\Form\AbstractType::buildForm()
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('analyzeAll', 'checkbox', array(
                'required' => false,
            ))
            ->add('submit', 'submit', array(
                'label' => 'Sprawdź',
            ))
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $data = $event->getData();
            $form = $event->getForm();

            $choices = array();
            $selected = array();
            foreach ($data as $block) {
                $choices[$block->id] = $block->id;
                if ($block->analyze) {
                    $selected[] = $block->id;
                }
            }

            $form->add('analyze', 'choice', array(
                'multiple' => true,
                'expanded' => true,
                'choices' => $choices,
                'data' => $selected,
            ));
        });
    }

    public function getName()
    {
        return 'analyze';
    }
}