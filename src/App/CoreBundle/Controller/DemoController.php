<?php

namespace App\CoreBundle\Controller;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use App\Model\Build;
use App\Model\Project;
use App\Model\Demo;

use RuntimeException;

class DemoController extends Controller
{
    private $demoConfig;

    private $demoUser;

    private function getSteps(Project $project)
    {
        $steps = [
            [
                'id' => 'prepare_build_container',
                'match' => 'configuring composer with token',
                'label' => 'Preparing build container',
                'tooltip' => 'This is the container where your project will be built. This steps include ssh keys generation and setup, for example.'
            ],
            [
                'id' => 'clone_repository',
                'match' => 'cloning repository',
                'label' => 'Cloning repository',
                'tooltip' => 'Next, we need to retrieve your code from github. Pretty straightforward.'
            ],
            [
                'id' => 'select_builder',
                'match' => '(custom build|app) detected',
                'label' => 'Selecting builder',
                'tooltip' => 'We need to determine what type of project you\'re trying to build (that is, if you did not specify a custom build). We use something much like heroku\'s buildpacks for that.',
            ],
            [
                'id' => 'install_dependencies',
                'match' => 'loading composer repositories with package information',
                'label' => 'Installing dependencies',
                'tooltip' => 'Every project has dependencies, let\'s fetch them! For example, the Symfony 2 builder will use composer to install dependencies.',
            ],
        ];

        if ($project->getName() === 'pim-community-standard') {
            $steps[] = [
                'id' => 'install_other_dependencies',
                'match' => 'running apt-get install',
                'label' => 'Installing runtime dependencies',
                'tooltip' => 'Sometimes the default runtime is not enough. Stage1 makes it easy to fine-tune the runtime to your needs.',
            ];

            $steps[] = [
                'id' => 'custom_install',
                'match' => 'clear extend cache folder',
                'label' => 'Custom installation script',
                'tooltip' => 'Some projects require a very specific installation process. Again, Stage1 doesn\'t get in the way and allow arbitrary build commands.',
            ];

            $steps[] = [
                'id' => 'install_assets',
                'match' => 'installing assets using the hard copy option',
                'label' => 'Installing assets',
                'tooltip' => 'Assets are the make-up of web application, special care has to be taken! Most Symfony 2 projects use Assetic to manage their assets.',
            ];
        }

        return $steps;
    }

    private function getDemoUser()
    {
        if (null === $this->demoUser) {
            $this->demoUser = $this->findUserByUsername($this->getDemoConfig('username'));
        }

        return $this->demoUser;
    }

    private function getDemoConfig($key = null)
    {
        if (null === $this->demoConfig) {
            $this->demoConfig = Yaml::parse($this->container->getParameter('kernel.root_dir').'/config/demo.yml');
        }

        if (null !== $key) {
            return $this->demoConfig[$key];
        }

        return $this->demoConfig;
    }

    public function disabledAction()
    {
        return $this->render('AppCoreBundle:Demo:disabled.html.twig');
    }

    public function indexAction(Request $request)
    {
        if (false === $this->getDemoConfig('enabled')) {
            return $this->disabledAction();
        }


        $session = $request->getSession();
        $session->set('demo_key', $session->get('demo_key', md5(uniqid(mt_rand(), true))));
        $channel = $session->get('demo_build_channel', 'demo-'.uniqid(mt_rand(), true));
        $build_id = $session->get('demo_build_id');

        if (null !== $build_id) {
            try {
                $build = $this->findBuild($build_id, false);

                if (!$build->isBuilding()) {
                    $session->remove('demo_build_id');
                    $channel = 'demo-'.uniqid(mt_rand(), true);
                }                
            } catch (NotFoundHttpException $e) {

            }
        }

        $session->set('demo_build_channel', $channel);

        foreach ($this->getDemoConfig('projects') as $project) {
            # @todo @slug
            $slugs[] = preg_replace('/[^a-z0-9\-]/', '-', strtolower($project));
        }

        $projects = $this->getDoctrine()->getRepository('Model:Project')->findBySlug($slugs);

        if (count($projects) === 0) {
            throw new RuntimeException('No demo projects found');
        }

        $websocketChannels = [];

        foreach ($projects as $project) {
            $websocketChannels[] = $project->getChannel();
        }

        $redis = $this->container->get('app_core.redis');
        $redis->del('channel:routing:'.$channel);

        array_unshift($websocketChannels, 'channel:routing:'.$channel);
        call_user_func_array(array($redis, 'sadd'), $websocketChannels);

        return $this->render('AppCoreBundle:Demo:index.html.twig', [
            'projects' => $projects,
            'channel' => $channel,
            'is_building' => (isset($build) && $build->isBuilding()),
        ]);
    }

    public function buildAction(Request $request)
    {
        if (false === $this->getDemoConfig('enabled')) {
            return $this->disabledAction();
        }

        $email = $request->get('email');

        $errors = [];

        if (strlen($email) === 0) {
            $errors['email'] = 'Your e-mail is required.';
        }

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Your e-mail seems to be invalid.';
        }

        $session = $request->getSession();
        $session->set('demo_key', $session->get('demo_key', md5(uniqid(mt_rand(), true))));

        # find project without checking user
        $project = $this->findProject($request->get('project_id'), false);

        if (!$project || !$project->getUsers()->contains($this->getDemoUser())) {
            $errors['project_id'] = 'Invalid demo project.';
        }

        $echo = [
            'project_id' => var_export($request->get('project_id'), true),
            'email' => var_export($request->get('email'), true),
        ];

        if (count($errors) > 0) {
            return new JsonResponse(json_encode(['status' => 400, 'errors' => $errors, 'echo' => $echo]), 200);
        }

        $ref = $this->getDemoConfig('default_build_ref');
        $hash = $this->getHashFromRef($project, $ref, $this->getDemoConfig('access_token'));
        $subdomain = preg_replace('/[^a-z0-9]+/', '-', $email);

        $build = new Build();
        $build->setProject($project);
        $build->setInitiator($this->getDemoUser());
        $build->setStatus(Build::STATUS_SCHEDULED);
        $build->setRef($ref);
        $build->setHash($hash);
        $build->setChannel($session->get('demo_build_channel'));
        $build->setStreamOutput(true);
        $build->setStreamSteps(true);
        $build->setHost(sprintf($this->container->getParameter('build_host_mask'), $subdomain.'.demo'));
        $build->setIsDemo(true);

        $previousBuild = $this->getDoctrine()->getRepository('Model:Build')->findPreviousBuild($build, false);

        $demo = new Demo();
        $demo->setEmail($email);
        $demo->setProject($project);
        $demo->setBuild($build);
        $demo->setDemoKey($session->get('demo_key'));

        $this->persistAndFlush($demo, $build);

        $request->getSession()->set('demo_build_id', $build->getId());

        $factory = $this->container->get('app_core.message.factory');
        
        $message = $factory->createBuildScheduled($build);
        $message->setExtra([
            'steps' => $this->getSteps($project),
            'previousBuild' => $previousBuild ? $previousBuild->asMessage() : null
        ]);

        $this->publishWebsocket($message);

        $producer = $this->get('old_sound_rabbit_mq.build_producer');
        $producer->publish(json_encode(['build_id' => $build->getId()]));

        # @todo @channel_auth
        $websocket_token = uniqid(mt_rand(), true);
        $this->get('app_core.redis')->sadd('channel:auth:' . $build->getChannel(), $websocket_token);

        return new JsonResponse(json_encode(['status' => 200, 'echo' => $echo, 'build_id' => $build->getId()]), 200);
    }
}