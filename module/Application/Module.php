<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/ZendSkeletonApplication for the canonical source repository
 * @copyright Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Application;

use Zend\Mvc\ModuleRouteListener;
use Zend\Mvc\MvcEvent;
use Zend\Permissions\Acl\Acl;
use Application\Role;
use Zend\Permissions\Acl\Resource\GenericResource as Resource;
use Zend\Authentication\AuthenticationService;
use Zend\Config\Config;

use Zend\Db\TableGateway\TableGateway;

class Module
{
    public function onBootstrap(MvcEvent $e)
    {
        $eventManager        = $e->getApplication()->getEventManager();
        $moduleRouteListener = new ModuleRouteListener();
        $moduleRouteListener->attach($eventManager);
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }
    
    public function getServiceConfig()
    {
        return array(
            'factories' => array(
                'Zend\Permissions\Acl\Acl' => function ($sm) {
                    
                    $acl = new Acl();
                    
                    $guest = new Role\Guest();
                    $client = new Role\Client();
                    $merchant = new Role\Merchant();
                    $staff = new Role\Staff();
                    $adminitrator = new Role\Adminitrator();
                    
                    /**
                     * Define Roles
                     */
                    $acl->addRole($guest)
                        ->addRole($client)
                        ->addRole($merchant)
                        ->addRole($staff)
                        ->addRole($adminitrator);
                    
                    /**
                     * Define Resources
                     */
                    $ControllerResource = array();
                    array_push($ControllerResource, new Resource('Setting\Controller\BaseSettingController'));
                    array_push($ControllerResource, new Resource('Install\Controller\IndexController'));
                    array_push($ControllerResource, new Resource('Workbench\Controller\BaseController'));
                    array_push($ControllerResource, new Resource('Merchant\Controller\BaseController'));
                    array_push($ControllerResource, new Resource('Cashier\Controller\BaseController'));
                    
                    foreach ($ControllerResource as $resource){
                        $acl->addResource($resource);
                    }
                    
                    /**
                     * Assigning permissions
                     */
                    $acl->allow($adminitrator,'Setting\Controller\BaseSettingController');
                    $acl->allow($adminitrator,'Install\Controller\IndexController');
                    $acl->allow($adminitrator,'Workbench\Controller\BaseController');
                    
                    $acl->allow($staff,'Workbench\Controller\BaseController');
                    
                    $acl->allow($client,'Merchant\Controller\BaseController');
                    
                    $acl->allow($guest,'Cashier\Controller\BaseController');
                    $acl->allow($guest,'Merchant\Controller\BaseController','login');
                    if (!$sm->get('SiteIsInstalled')) $acl->allow($guest,'Install\Controller\IndexController');
                    
                    return $acl;
                },
                'Acl' => function ($sm) {
                    return function ($resouce,$privilege=null) use($sm){
                        $acl = $sm->get('Zend\Permissions\Acl\Acl');
                        $role = $sm->get('GetCurrentRole');
                        
                        $allow = $acl->isAllowed($role, $resouce, $privilege );
                        
                        if ($allow) return true;
                        else{
                            throw new Role\NoPermissionsException();
                            
                        }
                    };
                },
                'GetCurrentRole' => function($sm){
                    
                    $role = new Role\Guest();
                    
                    $auth = new AuthenticationService();
                    if ($auth->hasIdentity()) {
                        if ($auth->getIdentity() === 'Administrator') $role = new Role\Adminitrator();
                        elseif ($auth->getIdentity() === 'Staff') $role = new Role\Staff();
                        
                    }else{
                        
                        // Client
                        $GetClientMerchantID = $sm->get('GetClientMerchantID');
                        $MerchantID = $GetClientMerchantID();
                        if ($MerchantID!==null) {
                            $role = new Role\Client();
                        }
                        
                    }
                    
                    return $role;
                },
                
                'GetClientMerchantID'=>function ($sm){
                    
                    return function () use($sm){
                        
                        $MerchantID = null;
                        
                        @session_start();
                        if (isset($_SESSION['Merchant']) && !empty($_SESSION['Merchant'])){
                            
                            if ($_SERVER['REMOTE_ADDR'] == $_SESSION['Merchant']['ip']){
                                
                                if (isset($_SESSION['Merchant']['id']) && !empty($_SESSION['Merchant']['id'])){
                                    
                                    // Get MerchantID from Session Cache
                                    $MerchantID = $_SESSION['Merchant']['id'];
                                }else{
                                    
                                    // Get MerchantID from DB by Merchant token(name)
                                    $GetMerchantIdByName = $sm->get('GetMerchantIdByName');
                                    $MerchantID = $GetMerchantIdByName($_SESSION['Merchant']['token']);
                                    
                                    $_SESSION['Merchant']['id'] = $MerchantID;
                                    
                                }
                                
                            }
                            
                        }
                        
                        return $MerchantID;
                        
                    };
                    
                },
                
                'GetMerchantIdByName'=>function ($sm){
                    return function ($name) use ($sm){
                        
                        $MerchantID = null;
                        
                        $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                        
                        $tableGateway = new TableGateway('merchant',$dbAdapter);
                        
                        $rs = $tableGateway->select(array('name'=>$name));
                        
                        if ($rs->count() > 0){
                            $data = $rs->current();
                        
                            $MerchantID = $data['id'];
                        
                        }else{
                        
                            // 添加一个商家记录
                            $rs_id = $tableGateway->insert(array(
                                'name'=>$name,
                            ));
                        
                            if ($rs_id){
                                $MerchantID = $rs_id;
                            }else{
                                throw new \Exception('Db Error !');
                            }
                        
                        }
                        
                        return $MerchantID;
                        
                    };
                },
                
                'GetMerchantNameByMerchantId'=>function ($sm){
                    return function ($MerchantID = NULL) use ($sm){
                    
                        $MerchantName = null;
                    
                        if ($MerchantID){
                            
                            $dbAdapter = $sm->get('Zend\Db\Adapter\Adapter');
                        
                            $tableGateway = new TableGateway('merchant',$dbAdapter);
                        
                            $rs = $tableGateway->select(array('id'=>$MerchantID));
                        
                            if ($rs->count() > 0){
                                $data = $rs->current();
                        
                                $MerchantName = $data['name'];
                        
                            }else{
                                $MerchantName = '未知商家';
                            }
                        
                        }
                    
                        return $MerchantName;
                    
                    };
                },
                
                'SiteIsInstalled'=> function (){
                    $config_file = 'config/autoload/local.php';
                    if (file_exists($config_file)) $config = include $config_file;
                    
                    $reader = new Config($config);
                    if (!empty($reader->db)) return true;
                    else return false;
                },
                
                'MysqlDatetimeMaker'=>function (){
                    
                    return function (){
                        return date('Y-n-j H:i:s',time());
                    };
                    
                },
                
            ),
        );
    }
}
