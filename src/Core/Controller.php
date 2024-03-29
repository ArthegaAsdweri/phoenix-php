<?php

namespace PhoenixPhp\Core;


use PhoenixPhp\PageWrapper\PageWrapper;
use PhoenixPhp\Utils\StringConversion;
use Symfony\Component\Yaml\Yaml;

/**
 * This class acts as the main entry point of the framework - utilizes the request, starts the session and returns the contents.
 */
class Controller
{

    //---- MEMBERS

    private string $page;
    private string $calledPage;
    private string $action;
    private ?string $argument;
    private ?array $aliases = null;
    private bool $ajaxCall = false;
    private bool $globalAjaxCall = false;
    private bool $debugMode = false;


    //---- CONSTRUCTOR

    /**
     * This method initializes the framework by starting the session and utilizing the request.
     */
    public function __construct()
    {
        $time = microtime(true);
        $this->setUpSession();
        if ($this->isDebugMode() === true) {
            Debugger::startExecutionTimer($time);
        }
        $this->utilizeConfig();
        $this->utilizeRequest();
    }


    //---- GENERAL METHODS

    /**
     * This method utilizes the framework and local user config.
     */
    private function utilizeConfig(): void
    {
        $globalConf = Yaml::parseFile(__DIR__ . '/../../config/phoenix.yml');
        if (isset($globalConf['Config'])) {
            foreach ($globalConf['Config'] as $constKey => $constValue) {
                define('PHPHP_' . $constKey, $constValue);
            }
        }

        $includePath = stream_resolve_include_path('config/config.yml');
        if ($includePath !== false) {
            $localConf = Yaml::parseFile($includePath);
            if (isset($localConf['Config'])) {
                foreach ($localConf['Config'] as $constKey => $constValue) {
                    if (!defined($constKey)) {
                        define('PHPHP_' . $constKey, $constValue);
                    }
                }
            }
        }
        if (isset($localConf['Aliases'])) {
            define('PHPHP_ALIASES', $localConf['Aliases']);
        }
    }

    /**
     * This method utilizes the framework specific request parameters.
     */
    private function utilizeRequest(): void
    {
        $stream = new PhpStream();
        $request = Request::getInstance($stream);
        $page = $request->retrieveParameter('requestPage');
        $action = $request->retrieveParameter('requestAction');
        $argument = $request->retrieveParameter('requestArgument');

        if ($page === null) {
            $page = 'home';
        }

        if ($action === null) {
            $action = 'default';
        }

        if (isset($_SERVER['HTTP_AJAX']) && $_SERVER['HTTP_AJAX'] === 'true') {
            $this->setAjaxCall(true);
        }

        if (defined('PHPHP_ALIASES')) {
            $this->setAliases(PHPHP_ALIASES);
        }

        $this->setCalledPage($page);
        $this->setPage($page);
        $this->setAction($action);
        $this->setArgument($argument);
    }

    /**
     * This method starts the session.
     */
    private function setUpSession(): void
    {
        $session = Session::getInstance();

        if ($session->retrieve('PHOENIX', 'DEBUGGER_ACTIVE') === true) {
            $this->setDebugMode(true);
        }
    }

    /**
     * This method determines an optional alias for the page.
     *
     * @param string $page name of the page
     * @return string $aliasPage the real page if the called page is an alias or the initial page if not
     */
    private function retrieveAliasForPage(string $page): string
    {
        $aliases = $this->getAliases();
        if ($aliases !== null) {
            foreach ($this->getAliases() as $aliasPage => $aliasOptions) {
                if(is_array($aliasOptions)) {
                    if(array_is_list($aliasOptions)) {
                        if (in_array($page, $aliasOptions)) {
                            return $aliasPage;
                        }                            
                    }
                    else {
                        if(in_array($page, array_keys($aliasOptions))) {
                            return $aliasPage;
                        }
                    }
                }
            }
        }
        return $page;
    }

    /**
     * This method determines an optional alias for the action.
     *
     * @param string $action name of the action
     * @return string $aliasAction the real action if the called action is an alias or the initial action if not
     */
    private function retrieveAliasForAction(string $action): string
    {
        $aliases = $this->getAliases();
        if ($aliases !== null) {
            foreach ($this->getAliases() as $aliasPage => $aliasOptions) {
                if (isset($aliasOptions['actions'])) {
                    foreach ($aliasOptions['actions'] as $aliasAction => $options) {
                        if (in_array($action, $options)) {
                            return $aliasAction;
                        }
                    }
                }
            }
        }
        return $action;
    }

    /**
     * This method determines the directory path.
     *
     * @return string generated path with page and its action
     */
    private function retrieveDir(): string
    {
        $page = ucfirst(StringConversion::toCamelCase($this->getPage()));
        $action = ucfirst(StringConversion::toCamelCase($this->getAction()));

        $dir = 'Pages';
        $suffix = 'Action';
        if ($this->isAjaxCall() === true) {
            $suffix = 'Ajax';
        }

        $pageDir = $dir . '/' . $page . '/';
        return $pageDir . $action . $suffix . '.php';
    }

    /**
     * This method determines whether the called file exists.
     *
     * @return bool true: the file exists, false: the file does not exist
     */
    private function checkFile(): bool
    {
        $actionFile = $this->retrieveDir();
        if (stream_resolve_include_path($actionFile)) {
            return true;
        }
        return false;
    }

    /**
     * This method returns the contents of the called page and its action.
     *
     * @return BasePage|string page contents
     */
    private function callPage(): BasePage|string
    {
        //was the link an alias?
        $aliasPage = $this->retrieveAliasForPage($this->getPage());
        if ($aliasPage !== $this->getPage()) {
            $this->setPage($aliasPage);
        }

        $originalAction = $this->getAction();

        //was the action an alias?
        $aliasAction = $this->retrieveAliasForAction($this->getAction());
        if ($aliasAction !== $this->getAction()) {
            $this->setAction($aliasAction);
        }

        //"real" page name request
        $fileCheck = $this->checkFile();
        if (!$fileCheck && $this->getAction() !== 'default') {
            $this->setAction('default');
            $fileCheck = $this->checkFile();
        }

        //action not found - default action?
        if (!$fileCheck) {
            $this->setAction($originalAction);
            $fileCheck = $this->checkFile(true);
        }

        //check for "global" class that might exist in the framework
        if (!$fileCheck && $this->getAction() !== 'default') {
            $this->setAction('default');
            $fileCheck = $this->checkFile(true);
        }

        //still not found -> framework default action?
        if (!$fileCheck) {
            $this->setPage('oops');
            $fileCheck = $this->checkFile();
            $this->setStatusCode404();
        }

        //rendering
        $classPage = ucfirst(StringConversion::toCamelCase($this->getPage()));
        $classString = '\Pages\\' . $classPage . '\\' . ucfirst($this->getAction() . 'Action');
        $globalClassName = '\PhoenixPhp' . $classString;
        $path = $this->retrieveDir();

        //framework class
        if (stream_resolve_include_path($path)) {
            try {
                /** @var BasePage $class */
                $className = PHPHP_PSR_NAMESPACE . $classString;
                $class = new $className();
                $class->setCalledPage($this->getCalledPage());
                $class->setCalledAction($originalAction);
                $class->setCalledArgument($this->getArgument());
            } //framework 404
            catch (\Error $e) {
                /** @var BasePage $class */
                $class = new $globalClassName();
                $class->setCalledPage($this->getCalledPage());
                $class->setCalledAction($originalAction);
                $class->setCalledArgument($this->getArgument());
            }
        }

        if (isset($class)) {
            if ($this->isDebugMode() === true) {
                $class->setDebugMode(true);
            }
            return $class;
        }
        return '';
    }

    /**
     * This method calls an Ajax request.
     *
     * @return string json that contains the ajax response
     * @throws Exception
     */
    private function callAjax(): string
    {
        if ($this->checkFile() === true) {
            $path = $this->retrieveDir();
            $globalPath = $this->retrieveDir(true);

            $action = StringConversion::toCamelCase($this->getAction());

            if (stream_resolve_include_path($path)) {
                $className = PHPHP_PSR_NAMESPACE . '\Pages\\' . ucfirst($this->getPage()) . '\\' . ucfirst(
                        $action . 'Ajax'
                    );
            }

            /** @var BasePage $className */
            $ajaxClass = new $className();
            $content = $ajaxClass->render();
        } else {
            $this->setStatusCode400();
            $content = 'Bad Request';
        }

        $responseArray = [
            'content' => $content
        ];

        if ($this->isDebugMode() === true) {
            $module = $this->callModule('debugger');
            $module->setDebugMode($this->isDebugMode());
            $module->setAjaxCall(true);
            $module->setAjaxAction($this->getAction());
            $responseArray['debug'] = $module->renderAjax();
        }

        return json_encode($responseArray);
    }

    /**
     * This method calls a global module.
     *
     * @param string $moduleName
     * @return BaseModule the called module
     */
    final protected function callModule(string $moduleName): BaseModule
    {
        //FIXME - registering CSS and JS via asset handler
        $className = '\ArthegaAsdweri\Phoenix\\Modules\\' . strtoupper($moduleName) . '\\DefaultAction';
        return new $className();
    }

    /**
     * This method completely renders everything.
     *
     * @return string rendered contents
     * @throws Exception
     */
    public function render(): string
    {
        if ($this->isAjaxCall() === true) {
            $content = $this->callAjax();
        } else {
            $path = 'PageWrapper/PageWrapper.php';
            if (defined('PHPHP_FILE_ROOT')) {
                $path = PHPHP_FILE_ROOT . '/PageWrapper/PageWrapper.php';
            }

            if (stream_resolve_include_path($path)) {
                try {
                    $wrapperContent = new PageWrapper;
                } catch (\Error $e) {
                    $className = PHPHP_PSR_NAMESPACE . '\PageWrapper\PageWrapper';
                    $wrapperContent = new $className;
                }
            }

            if ($this->isDebugMode() === true) {
                $wrapperContent->setDebugMode(true);
            }

            $pageContent = $this->callPage();
            $wrapperContent->renderPage($pageContent);
            $content = $wrapperContent->render();
        }

        return $content;
    }

    /**
     * This method defines the status code for unknown pages.
     */
    private function setStatusCode404(): void
    {
        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP / 1.0';
        header($protocol . ' 404 not found');
    }

    /**
     * This method defines the status code for internal errors.
     */
    private function setStatusCode400(): void
    {
        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP / 1.0';
        header($protocol . ' 400 bad request');
    }

    //---- SETTERS AND GETTERS

    /**
     * @return string
     */
    public function getPage(): string
    {
        return $this->page;
    }

    /**
     * @param string $page
     */
    public function setPage(string $page): void
    {
        $this->page = $page;
    }

    /**
     * @return string
     */
    public function getCalledPage(): string
    {
        return $this->calledPage;
    }

    /**
     * @param string $calledPage
     */
    public function setCalledPage(string $calledPage): void
    {
        $this->calledPage = $calledPage;
    }

    /**
     * @return string
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * @param string $action
     */
    public function setAction(string $action): void
    {
        $this->action = $action;
    }

    /**
     * @return string|null
     */
    public function getArgument(): ?string
    {
        return $this->argument;
    }

    /**
     * @param string|null $argument
     */
    public function setArgument(?string $argument): void
    {
        $this->argument = $argument;
    }

    /**
     * @return array|null
     */
    public function getAliases(): ?array
    {
        return $this->aliases;
    }

    /**
     * @param array|null $aliases
     */
    public function setAliases(?array $aliases): void
    {
        $this->aliases = $aliases;
    }

    /**
     * @return bool
     */
    public function isAjaxCall(): bool
    {
        return $this->ajaxCall;
    }

    /**
     * @param bool $ajaxCall
     */
    public function setAjaxCall(bool $ajaxCall): void
    {
        $this->ajaxCall = $ajaxCall;
    }

    /**
     * @return bool
     */
    public function isGlobalAjaxCall(): bool
    {
        return $this->globalAjaxCall;
    }

    /**
     * @param bool $globalAjaxCall
     */
    public function setGlobalAjaxCall(bool $globalAjaxCall): void
    {
        $this->globalAjaxCall = $globalAjaxCall;
    }

    /**
     * @return bool
     */
    public function isDebugMode(): bool
    {
        return $this->debugMode;
    }

    /**
     * @param bool $debugMode
     */
    public function setDebugMode(bool $debugMode): void
    {
        $this->debugMode = $debugMode;
    }
}