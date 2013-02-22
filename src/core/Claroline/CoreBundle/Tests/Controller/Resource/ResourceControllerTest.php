<?php

namespace Claroline\CoreBundle\Controller;

use Claroline\CoreBundle\Library\Testing\FunctionalTestCase;
use Claroline\CoreBundle\Entity\Resource\Directory;

class ResourceControllerTest extends FunctionalTestCase
{
    private $resourceRepository;
    private $upDir;
    private $pwr;

    public function setUp()
    {
        parent::setUp();
        $this->loadPlatformRoleData();
        $this->loadUserData(array('user' => 'user', 'admin' => 'admin'));
        $this->client->followRedirects();
        $ds = DIRECTORY_SEPARATOR;
        $this->originalPath = __DIR__ . "{$ds}..{$ds}..{$ds}Stub{$ds}files{$ds}originalFile.txt";
        $this->copyPath = __DIR__ . "{$ds}..{$ds}..{$ds}Stub{$ds}files{$ds}copy.txt";
        $this->upDir = $this->client->getContainer()->getParameter('claroline.files.directory');
        $this->thumbsDir = $this->client->getContainer()->getParameter('claroline.thumbnails.directory');
        $this->resourceRepository = $this
            ->client
            ->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('ClarolineCoreBundle:Resource\AbstractResource');
        $this->pwr = $this->getDirectory('user');
    }

    public function tearDown()
    {
        parent::tearDown();
        $this->cleanDirectory($this->upDir);
        $this->cleanDirectory($this->thumbsDir);
    }

    public function testDirectoryCreationFormCanBeDisplayed()
    {
        $this->logUser($this->getFixtureReference('user/user'));
        $crawler = $this->client->request('GET', 'resource/form/directory');
        $form = $crawler->filter('#directory_form');
        $this->assertEquals(count($form), 1);
    }

    public function testDirectoryFormErrorsAreDisplayed()
    {
        $this->logUser($this->getFixtureReference('user/user'));
        $crawler = $this->client->request(
            'POST',
            "/resource/create/directory/{$this->pwr->getId()}",
            array('directory_form' => array('name' => null, 'shareType' => 1))
        );

        $form = $crawler->filter('#directory_form');
        $this->assertEquals(count($form), 1);
    }

    public function testMove()
    {
        $this->loadFileData('user', 'user', array('file.txt'));
        $this->loadDirectoryData('user', array('user/container'));
        $this->createBigTree('user');
        $this->logUser($this->getFixtureReference('user/user'));
        $treeRoot = $this->getDirectory('treeRoot');
        $loneFile = $this->getFile('file.txt');
        $container = $this->getDirectory('container');
        $this->client->request(
            'GET',
            "/resource/move/{$container->getId()}?ids[]={$treeRoot->getId()}&ids[]={$loneFile->getId()}"
        );
        $this->client->request('GET', "/resource/directory/{$this->getDirectory('container')->getId()}");
        $dir = json_decode($this->client->getResponse()->getContent());
        $this->assertObjectHasAttribute('resources', $dir);
        $this->assertEquals(2, count($dir->resources));
    }

    public function testCopy()
    {
        $this->loadFileData('user', 'user', array('file.txt'));
        $this->createBigTree('user');
        $this->logUser($this->getFixtureReference('user/user'));
        $treeRoot = $this->getDirectory('treeRoot');
        $loneFile = $this->getFile('file.txt');
        $this->client->request(
            'GET',
            "/resource/copy/{$this->pwr->getId()}?ids[]={$treeRoot->getId()}&ids[]={$loneFile->getId()}"
        );
        $this->client->request('GET', "/resource/directory/{$this->pwr->getId()}");
        $dir = json_decode($this->client->getResponse()->getContent());
        $this->assertObjectHasAttribute('resources', $dir);
        $this->assertEquals(4, count($dir->resources));
    }

    public function testGetEveryInstancesIdsFromExportArray()
    {
        $this->loadFileData('user', 'user', array('file.txt'));
        $this->createBigTree('user');
        $this->logUser($this->getFixtureReference('user/user'));
        $toExport = $this->client
            ->getContainer()
            ->get('claroline.resource.exporter')
            ->expandResourceIds((array) $this->getDirectory('treeRoot')->getId());
        $this->assertEquals(4, count($toExport));
        $toExport = $this->client
            ->getContainer()
            ->get('claroline.resource.exporter')
            ->expandResourceIds((array) $this->getFile('file.txt')->getId());
        $this->assertEquals(1, count($toExport));
        $complexExportList = array();
        $complexExportList[] = $this->pwr->getId();
        $complexExportList[] = $this->getFile('file.txt')->getId();
        $toExport = $this->client
            ->getContainer()
            ->get('claroline.resource.exporter')
            ->expandResourceIds($complexExportList);
        $this->assertEquals(6, count($toExport));
    }

    public function testExport()
    {
        $this->logUser($this->getFixtureReference('user/user'));
        ob_start();
        $this->client->request('GET', "/resource/export?ids[]={$this->pwr->getId()}");
        ob_end_clean();
        $headers = $this->client->getResponse()->headers;
        $this->assertTrue($headers->contains('Content-Disposition', 'attachment; filename=archive'));
    }

    public function testMultiExportThrowsAnExceptionWithoutParameters()
    {
        $this->logUser($this->getFixtureReference('user/user'));
        $crawler = $this->client->request('GET', "/resource/export");
        $this->assertEquals(500, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(
            1,
            count($crawler->filter('html:contains("You must select some resources to export.")'))
        );
    }

    public function testCustomActionThrowExceptionOnUknownAction()
    {
        $this->logUser($this->getFixtureReference('user/user'));
        $crawler = $this->client->request(
            'GET',
            "resource/custom/directory/thisactiondoesntexist/{$this->pwr->getId()}"
        );
        $this->assertEquals(500, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(1, count($crawler->filter('html:contains("return any Response")')));
    }

    /**
     * @todo Test the exception if the directory id parameter doesn't match any directory.
     * @todo Refactoring the repository.
     * @todo Test the date filter.
     * @todo Test if not in the Desktop (workspaceId != 0).
     */
    public function testFilters()
    {
        $this->createBigTree('user');
        $this->logUser($this->getFixtureReference('user/user'));
        //sleep(2); // Pause to allow us to filter on creation date
        //$wsEroot = $this->resourceRepository->findWorkspaceRoot($this->getFixtureReference('workspace/ws_e'));
        //$this->createBigTree($wsEroot, $admin);
        $now = new \DateTime();
        //filter by types (1)
        $crawler = $this->client->request('GET', '/resource/filter/0?types[]=file');
        $result = json_decode($this->client->getResponse()->getContent());
        $resources = $result->resources;
        $this->assertEquals(3, count($resources));
        /*
        //filter by datecreation
        $crawler = $this->client->request(
            'GET',
            "/resource/filter/0?dateFrom={$creationTimeAdminTreeOne->format('Y-m-d H:i:s')}"
        );
        $result = json_decode($this->client->getResponse()->getContent());
        $resources = $result->resources;
        var_dump($this->client->getResponse()->getContent());
        $this->assertEquals(5, count($resources));

        $crawler = $this->client->request('GET', "/resource/filter/0?dateTo={$now->format('Y-m-d H:i:s')}");
        $result = json_decode($this->client->getResponse()->getContent());
        $resources = $result->resources;
        $this->assertEquals(6, count($resources));

        $crawler = $this->client->request(
          'GET',
          "/resource/filter/0?dateFrom={$creationTimeAdminTreeOne->format('Y-m-d H:i:s')}
          &dateTo={$now->format('Y-m-d H:i:s')}
        ");

        $result = json_decode($this->client->getResponse()->getContent());
        $resources = $result->resources;
        $this->assertEquals(5, count($resources));
        */
        //filter by name
        $crawler = $this->client->request('GET', "/resource/filter/0?name=file1");
        $result = json_decode($this->client->getResponse()->getContent());
        $resources = $result->resources;
        $this->assertEquals(1, count($resources));

        //filter by mime
        /* This filter is not active for now (see ResourceController::filterAction's todo)
        $crawler = $this->client->request('GET', "/resource/filter?mimeTypes[]=text");
        $this->assertEquals(6, count(json_decode($this->client->getResponse()->getContent())));
        */
    }

    public function testDelete()
    {
        $this->createBigTree('user');
        $this->loadFileData('user', 'user', array('file.txt'));
        $this->logUser($this->getFixtureReference('user/user'));
        $crawler = $this->client->request('GET', "/resource/directory/{$this->pwr->getId()}");
        $dir = json_decode($this->client->getResponse()->getContent());
        $this->assertObjectHasAttribute('resources', $dir);
        $this->assertEquals(2, count($dir->resources));
        $this->client->request(
            'GET', "/resource/delete?ids[]={$this->getDirectory('treeRoot')->getId()}&ids[]={$this->getFile('file.txt')->getId()}"
        );
        $crawler = $this->client->request('GET', "/resource/directory/{$this->pwr->getId()}");
        $dir = json_decode($this->client->getResponse()->getContent());
        $this->assertObjectHasAttribute('resources', $dir);
        $this->assertEquals(0, count($dir->resources));
    }

    public function testDeleteRootThrowsAnException()
    {
        $this->logUser($this->getFixtureReference('user/user'));
        $crawler = $this->client->request('GET', "/resource/delete?ids[]={$this->pwr->getId()}");
        $this->assertEquals(500, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(1, count($crawler->filter('html:contains("Root directory cannot be removed")')));
    }

    public function testCustomActionLogsEvent()
    {
        $this->markTestSkipped('no custom action defined yet');
        $this->loadFileData('user', 'user', array('file.txt'));
        $file = $this->getFile('file.txt');
        $this->logUser($this->getFixtureReference('user/user'));
        $preEvents = $this->client
            ->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('ClarolineCoreBundle:Logger\ResourceLog')
            ->findAll();
        $this->client->request('GET', "/resource/custom/file/open/{$file->getId()}");
        $postEvents = $this->client
            ->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('ClarolineCoreBundle:Logger\ResourceLog')
            ->findAll();
        $this->assertEquals(1, count($postEvents) - count($preEvents));
    }

    public function testOpenActionLogsEvent()
    {
        $this->loadFileData('user', 'user', array('file.txt'));
        $file = $this->getFile('file.txt');
        $this->logUser($this->getFixtureReference('user/user'));
        $preEvents = $this->client
            ->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('ClarolineCoreBundle:Logger\ResourceLog')
            ->findAll();
        $this->client->request('GET', "/resource/open/file/{$file->getId()}");
        $postEvents = $this->client
            ->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('ClarolineCoreBundle:Logger\ResourceLog')
            ->findAll();
        $this->assertEquals(1, count($postEvents) - count($preEvents));
    }

    public function testCreateActionLogsEventWithResourceManager()
    {
        $this->logUser($this->getFixtureReference('user/user'));
        $user = $this->client->getContainer()->get('security.context')->getToken()->getUser();
        $preEvents = $this->client
            ->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('ClarolineCoreBundle:Logger\ResourceLog')
            ->findAll();
        $manager = $this->client->getContainer()->get('claroline.resource.manager');
        $directory = new Directory();
        $directory->setName('dir');
        $manager->create($directory, $this->pwr->getId(), 'directory', $user);
        $postEvents = $this->client
            ->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('ClarolineCoreBundle:Logger\ResourceLog')
            ->findAll();
        $this->assertEquals(1, count($postEvents) - count($preEvents));
    }

    public function testMultiDeleteActionLogsEvent()
    {
        $this->createBigTree('user');
        $this->loadFileData('user', 'user', array('file.txt'));
        $treeRoot = $this->getDirectory('treeRoot');
        $loneFile = $this->getFile('file.txt');
        $this->logUser($this->getFixtureReference('user/user'));
        $this->client->request('GET', "/resource/directory/{$this->pwr->getId()}");
        $dir = json_decode($this->client->getResponse()->getContent());
        $this->assertObjectHasAttribute('resources', $dir);
        $this->assertEquals(2, count($dir->resources));
        $preEvents = $this->client
            ->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('ClarolineCoreBundle:Logger\ResourceLog')
            ->findAll();
        $this->client->request(
            'GET', "/resource/delete?ids[]={$treeRoot->getId()}&ids[]={$loneFile->getId()}"
        );

        $postEvents = $this->client
            ->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('ClarolineCoreBundle:Logger\ResourceLog')
            ->findAll();
        $this->assertEquals(6, count($postEvents) - count($preEvents));
    }

    public function testMultiMoveLogsEvent()
    {
        $this->createBigTree('user');
        $this->loadFileData('user', 'user', array('file.txt'));
        $this->loadDirectoryData('user', array('user/container'));
        $container = $this->getDirectory('container');
        $treeRoot = $this->getDirectory('treeRoot');
        $loneFile = $this->getFile('file.txt');
        $this->logUser($this->getFixtureReference('user/user'));
        $preEvents = $this->client
            ->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('ClarolineCoreBundle:Logger\ResourceLog')
            ->findAll();
        $this->client->request(
            'GET',
            "/resource/move/{$container->getId()}?ids[]={$treeRoot->getId()}&ids[]={$loneFile->getId()}"
        );
        $postEvents = $this->client
            ->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('ClarolineCoreBundle:Logger\ResourceLog')
            ->findAll();
        $this->assertEquals(2, count($postEvents) - count($preEvents));
    }

    public function testMultiExportLogsEvent()
    {
        $this->createBigTree('user');
        $this->loadFileData('user', 'user', array('file.txt'));
        $treeRoot = $this->getDirectory('treeRoot');
        $loneFile = $this->getFile('file.txt');
        $this->logUser($this->getFixtureReference('user/user'));
        $preEvents = $this->client
            ->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('ClarolineCoreBundle:Logger\ResourceLog')
            ->findAll();
        ob_start();
        $this->client->request(
            'GET',
            "/resource/export?ids[]={$treeRoot->getId()}&ids[]={$loneFile->getId()}"
        );
        ob_clean();
        $postEvents = $this->client
            ->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('ClarolineCoreBundle:Logger\ResourceLog')
            ->findAll();
        $this->assertEquals(5, count($postEvents) - count($preEvents));
    }

    public function testCreateShortcutAction()
    {
        $this->loadFileData('user', 'user', array('file.txt'));
        $file = $this->getFile('file.txt');
        $this->logUser($this->getFixtureReference('user/user'));
        $this->client->request('GET', "/resource/shortcut/{$this->pwr->getId()}/create?ids[]={$file->getId()}");
        $this->client->request('GET', "/resource/directory/{$this->pwr->getId()}");
        $dir = json_decode($this->client->getResponse()->getContent());
        $this->assertObjectHasAttribute('resources', $dir);
        $this->assertEquals(2, count($dir->resources));
    }

    public function testOpenFileShortcut()
    {
        $this->loadFileData('user', 'user', array('file.txt'));
        $file = $this->getFile('file.txt');
        $this->logUser($this->getFixtureReference('user/user'));
        $this->client->request('GET', "/resource/shortcut/{$this->pwr->getId()}/create?ids[]={$file->getId()}");
        $jsonResponse = json_decode($this->client->getResponse()->getContent());
        $this->client->request('GET', "/resource/open/file/{$file->getId()}");
        $openFile = $this->client->getResponse()->getContent();
        $this->client->request('GET', "/resource/open/file/{$jsonResponse[0]->id}");
        $openShortcut = $this->client->getResponse()->getContent();
        $this->assertEquals($openFile, $openShortcut);
    }

    public function testChildrenShortcut()
    {
        $this->createBigTree('user');
        $rootDir = $this->getDirectory('treeRoot');
        $this->logUser($this->getFixtureReference('user/user'));
        $this->client->request('GET', "/resource/shortcut/{$this->pwr->getId()}/create?ids[]={$rootDir->getId()}");
        $jsonResponse = json_decode($this->client->getResponse()->getContent());
        $this->client->request('GET', "/resource/directory/{$jsonResponse[0]->id}");
        $openShortcut = $this->client->getResponse()->getContent();
        $this->client->request('GET', "/resource/directory/{$rootDir->getId()}");
        $openDirectory = $this->client->getResponse()->getContent();
        $this->assertEquals($openDirectory, $openShortcut);
    }

    public function testDeleteShortcut()
    {
        $this->loadFileData('user', 'user', array('file.txt'));
        $file = $this->getFile('file.txt');
        $this->logUser($this->getFixtureReference('user/user'));
        $this->client->request('GET', "/resource/shortcut/{$this->pwr->getId()}/create?ids[]={$file->getId()}");
        $jsonResponse = json_decode($this->client->getResponse()->getContent());
        $this->client->request('GET', "/resource/delete?ids[]={$jsonResponse[0]->id}");
        $this->client->request('GET', "/resource/directory/{$this->pwr->getId()}");
        $dir = json_decode($this->client->getResponse()->getContent());
        $this->assertObjectHasAttribute('resources', $dir);
        $this->assertEquals(1, count($dir->resources));
    }

    public function testDeleteShortcutTarget()
    {
        $this->loadFileData('user', 'user', array('file.txt'));
        $file = $this->getFile('file.txt');
        $this->logUser($this->getFixtureReference('user/user'));
        $this->client->request('GET', "/resource/shortcut/{$this->pwr->getId()}/create?ids[]={$file->getId()}");
        $this->client->request('GET', "/resource/delete?ids[]={$file->getId()}");
        $this->client->request('GET', "/resource/directory/{$this->pwr->getId()}");
        $dir = json_decode($this->client->getResponse()->getContent());
        $this->assertObjectHasAttribute('resources', $dir);
        $this->assertEquals(0, count($dir->resources));
    }

    public function testOpenDirectoryAction()
    {
        $this->loadDirectoryData('user', array('user/Foo/Bar'));
        $this->loadFileData('user', 'Bar', array('Baz'));
        $this->loadFileData('user', 'Bar', array('Bat'));
        $allVisibleResourceTypes = $this->getEntityManager()
            ->getRepository('ClarolineCoreBundle:Resource\ResourceType')
            ->findByIsVisible(true);

        $this->logUser($this->getFixtureReference('user/user'));
        $this->client->request('GET', "/resource/directory/{$this->getDirectory('Bar')->getId()}");
        $jsonResponse = json_decode($this->client->getResponse()->getContent());
        $this->assertObjectHasAttribute('path', $jsonResponse);
        $this->assertObjectHasAttribute('creatableTypes', $jsonResponse);
        $this->assertObjectHasAttribute('resources', $jsonResponse);
        $this->assertEquals(3, count($jsonResponse->path));
        $this->assertEquals(count($allVisibleResourceTypes), count((array) $jsonResponse->creatableTypes));
        $this->assertEquals(2, count((array) $jsonResponse->resources));
    }

    public function testOpenDirectoryReturnsTheRootDirectoriesIfDirectoryIdIsZero()
    {
        $this->logUser($this->getFixtureReference('user/user'));
        $this->client->request('GET', "/resource/directory/0");
        $jsonResponse = json_decode($this->client->getResponse()->getContent());
        $this->assertObjectHasAttribute('path', $jsonResponse);
        $this->assertObjectHasAttribute('creatableTypes', $jsonResponse);
        $this->assertObjectHasAttribute('resources', $jsonResponse);
        $this->assertEquals(0, count($jsonResponse->path));
        $this->assertEquals(0, count((array) $jsonResponse->creatableTypes));
        $this->assertEquals(1, count((array) $jsonResponse->resources));
    }

    public function testOpenDirectoryThrowsAnExceptionIfDirectoryDoesntExist()
    {
        $this->logUser($this->getFixtureReference('user/user'));
        $this->client->request('GET', "/resource/directory/123456");
        $this->assertEquals(500, $this->client->getResponse()->getStatusCode());
    }

    public function testOpenDirectoryThrowsAnExceptionIfResourceIsNotADirectory()
    {
        $this->loadFileData('user', 'user', array('Bar'));
        $file = $this->getFile('Bar');
        $this->logUser($this->getFixtureReference('user/user'));
        $this->client->request('GET', "/resource/directory/{$file->getId()}");
        $this->assertEquals(500, $this->client->getResponse()->getStatusCode());
    }

    private function createBigTree($userReferenceName)
    {
        $this->loadDirectoryData($userReferenceName, array($userReferenceName.'/treeRoot/dir2'));
        $this->loadFileData($userReferenceName, 'treeRoot', array('file1.pdf'));
        $this->loadFileData($userReferenceName, 'treeRoot', array('file2.pdf'));
        $this->loadFileData($userReferenceName, 'dir2', array('file3.pdf'));
    }

    private function cleanDirectory($dir)
    {
        $iterator = new \DirectoryIterator($dir);

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== 'placeholder'
                && $file->getFilename() !== 'originalFile.txt'
                && $file->getFilename() !== 'originalZip.zip'
            ) {
                chmod($file->getPathname(), 0777);
                unlink($file->getPathname());
            }
        }
    }
}