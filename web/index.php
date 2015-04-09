<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Silex\Provider\FormServiceProvider;

use Symfony\Component\Validator\Constraints as Assert;
use Silex\Application;

$app = new Silex\Application();
$app['debug'] = true;

$app->register(new FormServiceProvider());
$app->register(new Silex\Provider\ValidatorServiceProvider());
$app->register(new Silex\Provider\TranslationServiceProvider(), array(
    'translator.domains' => array()
));

// register templates
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__ . '/../views',
    'twig.options' => array(
        'cache' => __DIR__ . '/../cache/twig'
    )
));

$app['twig'] = $app->share($app->extend('twig', function ($twig, $app)
{
    $twig->addFunction(new \Twig_SimpleFunction('asset', function ($asset)
    {
        // implement whatever logic you need to determine the asset path
        return sprintf('/../web/assets/%s', ltrim($asset, '/'));
    }));
    return $twig;
}));

// mouting controllers
$app->mount('/okofen', new Okofen\OkofenController());

// default route
$app->get('/', function (Request $request) use($app)
{
    $form = buildLoginForm($app);
    
    $form->handleRequest($request);
    
    return $app['twig']->render('index.html', array(
        'form' => $form->createView()
    ));
});

function buildLoginForm(Application $app, $data = null)
{
    $form = $app['form.factory']->createBuilder('form', $data)
        ->add('username', 'text', array(
        'constraints' => array(
            new Assert\NotBlank(),
            new Assert\Length(array(
                'min' => 5
            ))
        )
    ))
        ->add('password', 'password', array(
        'constraints' => new Assert\NotBlank()
    ))
        ->add('submit', 'submit')
        ->getForm();
    
    return $form;
}

$app->run();