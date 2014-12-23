<?php

namespace Oro\Bundle\CommentBundle\Tests\Unit\Form\Type;

use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

use Oro\Bundle\EntityConfigBundle\Config\ConfigManager;
use Oro\Bundle\CommentBundle\Entity\Comment;
use Oro\Bundle\CommentBundle\Form\Type\CommentTypeApi;

class CommentTypeApiTest extends \PHPUnit_Framework_TestCase
{
    /** @var ConfigManager|\PHPUnit_Framework_MockObject_MockObject */
    protected $configManager;

    public function setUp()
    {
        $this->configManager = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\Config\ConfigManager')
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function testBuildForm()
    {
        /** @var \PHPUnit_Framework_MockObject_MockObject | FormBuilderInterface $builder */
        $builder = $this->getMock('\Symfony\Component\Form\FormBuilder', [], [], '', false);
        $builder->expects($this->at(0))
            ->method('add')
            ->with(
                'message',
                'textarea',
                [
                'required' => true,
                'label'    => 'oro.comment.message.label',
                'attr'     => [
                    'class' => 'comment-text-field',
                    'placeholder' => 'oro.comment.message.placeholder'
                    ],
                ]
            )
            ->will($this->returnSelf());
        $builder->expects($this->once())
            ->method('addEventSubscriber')
            ->with($this->isInstanceOf('Oro\Bundle\SoapBundle\Form\EventListener\PatchSubscriber'))
            ->will($this->returnSelf());
        $formType = new CommentTypeApi($this->configManager);
        $formType->buildForm($builder, []);
    }

    public function testSetDefaultOptions()
    {
        /** @var \PHPUnit_Framework_MockObject_MockObject | OptionsResolverInterface $resolver */
        $resolver = $this->getMock('\Symfony\Component\OptionsResolver\OptionsResolverInterface');
        $resolver->expects($this->once())
            ->method('setDefaults')
            ->with([
                'data_class'              => Comment::ENTITY_NAME,
                'intention'               => 'comment',
                'csrf_protection'         => false,
                'allow_add'               => true,
            ]);

        $formType = new CommentTypeApi($this->configManager);
        $formType->setDefaultOptions($resolver);
    }

    public function testReturnFormName()
    {
        $formType = new CommentTypeApi($this->configManager);
        $this->assertEquals(CommentTypeApi::FORM_NAME, $formType->getName());
    }
}
