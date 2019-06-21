<?php

namespace Webkul\UVDesk\CoreBundle\Repository;

use Doctrine\ORM\Query;
use Doctrine\Common\Collections\Criteria;
use Webkul\UVDesk\CoreBundle\Entity\User;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * UserRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class UserRepository extends \Doctrine\ORM\EntityRepository
{
    const LIMIT = 10;
    public $safeFields = ['page', 'limit', 'sort', 'order', 'direction'];

    public function getAllAgents(ParameterBag $params = null, ContainerInterface $container) {
        $params = !empty($params) ? array_reverse($params->all()) : [];
        $queryBuilder = $this->getEntityManager()->createQueryBuilder()
            ->select("user, userInstance, supportRole")
            ->from('UVDeskCoreBundle:User', 'user')
            ->leftJoin('user.userInstance', 'userInstance')
            ->leftJoin('userInstance.supportRole', 'supportRole')
            ->where('supportRole.id != :customerRole')->setParameter('customerRole', 4)
            ->orderBy('userInstance.createdAt', !isset($params['sort']) ? Criteria::DESC : Criteria::ASC);

        foreach ($params as $field => $fieldValue) {
            if (in_array($field, $this->safeFields))
                continue;
            
            if (!in_array($field, ['dateUpdated', 'dateAdded', 'search', 'isActive'])) {
                $queryBuilder->andWhere("user.$field = :$field")->setParameter($field, $fieldValue);
            } else {
                if ('search' == $field) {
                    $queryBuilder->andwhere("user.firstName LIKE :fullName OR user.email LIKE :email")
                        ->setParameter('fullName', '%' . urldecode(trim($fieldValue)) . '%')
                        ->setParameter('email', '%' . urldecode(trim($fieldValue)) . '%');
                } else if ('isActive' == $field) {
                    $queryBuilder->andWhere('userInstance.isActive = :isActive')->setParameter('isActive', $fieldValue);
                }
            }
        }

        // Pagination
        $options = ['distinct' => true, 'wrap-queries' => true];
        $currentPage = isset($params['page']) ? $params['page'] : 1;
        
        $paginationQueryBuilder = clone $queryBuilder;
        $totalUsers = (int) $paginationQueryBuilder->select('COUNT (DISTINCT user.id)')->getQuery()->getSingleScalarResult();
        $query = $queryBuilder->getQuery()->setHydrationMode(Query::HYDRATE_ARRAY)->setHint('knp_paginator.count', $totalUsers);

        $pagination = $container->get('knp_paginator')->paginate($query, $currentPage, self::LIMIT, $options);
        
        // Parse result
        $paginationParams = $pagination->getParams();
        $paginationAttributes = $pagination->getPaginationData();
        
        $paginationParams['page'] = 'replacePage';
        $paginationAttributes['url'] = '#' . $container->get('uvdesk.service')->buildPaginationQuery($paginationParams);
        
        return [
            'pagination_data' => $paginationAttributes,
            'users' => array_map(function ($user) {
                return [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'smallThumbnail' => $user['userInstance'][0]['profileImagePath'] ?: null,
                    'isActive' => $user['userInstance'][0]['isActive'],
                    'name' => ucwords(trim(implode(' ', [$user['firstName'], $user['lastName']]))),
                    'role' => $user['userInstance'][0]['supportRole']['description'],
                    'roleCode' =>  $user['userInstance'][0]['supportRole']['code'],
                ];
            }, $pagination->getItems()),
        ];
    }

    public function getAllAgentsForChoice(ParameterBag $obj = null, $container)
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('a')->from($this->getEntityName(), 'a')
                ->leftJoin('a.userInstance', 'userInstance')
                ->leftJoin('userInstance.supportRole', 'supportRole')
                ->andwhere('userInstance.supportRole NOT IN (:roles)')
                ->setParameter('roles', [4]);

        return $qb;
    }

    public function getAllCustomer(ParameterBag $obj = null, $container)
    {
        $json = array();
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('a,userInstance')->from($this->getEntityName(), 'a');
        $qb->leftJoin('a.userInstance', 'userInstance');
        $qb->addSelect("CONCAT(a.firstName,' ',a.lastName) AS name");

        $data = $obj->all();
        $data = array_reverse($data);
        foreach ($data as $key => $value) {
            if(!in_array($key,$this->safeFields)) {
                if($key!='dateUpdated' AND $key!='dateAdded' AND $key!='search' AND $key!='starred' AND $key!='isActive') {
                    $qb->Andwhere('a.'.$key.' = :'.$key);
                    $qb->setParameter($key, $value);
                } else {
                    if($key == 'search') {
                        $qb->Andwhere("CONCAT(a.firstName,' ', a.lastName) LIKE :fullName OR a.email LIKE :email");
                        $qb->setParameter('fullName', '%'.urldecode($value).'%'); 
                        $qb->setParameter('email', '%'.urldecode($value).'%');    
                    } elseif($key == 'starred') {
                        $qb->andwhere('userInstance.isStarred = 1');
                    } else if($key == 'isActive') {
                        $qb->andwhere('userInstance.isActive = :isActive');
                        $qb->setParameter('isActive', $value);
                    }
                }
            }
        } 

        $qb->andwhere('userInstance.supportRole = :roles');
        $qb->setParameter('roles', 4);

        if(!isset($data['sort'])){
            $qb->orderBy('userInstance.createdAt',Criteria::DESC);
        }

        $paginator  = $container->get('knp_paginator');

        $newQb = clone $qb;
        $newQb->select('DISTINCT a.id');
        $results = $paginator->paginate(
            $qb->getQuery()->setHydrationMode(Query::HYDRATE_ARRAY)->setHint('knp_paginator.count', count($newQb->getQuery()->getResult())),
            isset($data['page']) ? $data['page'] : 1,
            self::LIMIT,
            array('distinct' => true, 'wrap-queries' => true)
        );

        $paginationData = $results->getPaginationData();
        $queryParameters = $results->getParams();
        $queryParameters['page'] = "replacePage";
        $paginationData['url'] = '#'.$container->get('uvdesk.service')->buildPaginationQuery($queryParameters);

        $this->container = $container;
        $data = array();

        foreach ($results as $key => $customer) {
            $data[] = array(
                                'id' => $customer[0]['id'],
                                'email' => $customer[0]['email'],
                                'smallThumbnail' => $customer[0]['userInstance'][0]['profileImagePath'],
                                'isStarred' => $customer[0]['userInstance'][0]['isStarred'],
                                'isActive' => $customer[0]['userInstance'][0]['isActive'],
                                'name' => $customer[0]['firstName'].' '.$customer[0]['lastName'],
                                'source' => $customer[0]['userInstance'][0]['source'],
                                'count' => $this->getCustomerTicketCount($customer[0]['id']),
                            );
        }   
        $json['customers'] = $data;
        $json['pagination_data'] = $paginationData;
        $json['customer_count'] = $this->getCustomerCountDetails($container);
       
        return $json;
    }


    public function getCustomerCountDetails($container) {
        $starredQb = $this->getEntityManager()->createQueryBuilder();
        $starredQb->select('COUNT(u.id) as countUser')
                ->from($this->getEntityName(), 'u')
                ->leftJoin('u.userInstance', 'userInstance')
                ->andwhere('userInstance.isActive = 1')
                ->Andwhere('userInstance.supportRole = :roles')
                ->setParameter('roles', 4);

        $all = $starredQb->getQuery()->getResult();

        $starredQb->andwhere('userInstance.isStarred = 1');
        $starred = $starredQb->getQuery()->getResult();

        return array('all' => $all[0]['countUser'],'starred' => $starred[0]['countUser']);
    }

    public function getCustomerTicketCount($customerId) {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('COUNT(t.id) as countTicket')->from('UVDeskCoreBundle:Ticket', 't');
        $qb->andwhere('t.status = 1');
        $qb->andwhere('t.isTrashed != 1');
        $qb->andwhere('t.customer = :customerId');
        $qb->setParameter('customerId', $customerId);
        $result = $qb->getQuery()->getResult();
        return $result[0]['countTicket'];
    }

    public function getAgentByEmail($username)
    {
        $query = $this->getEntityManager()
            ->createQuery(
                'SELECT u, dt FROM UVDeskCoreBundle:User u
                JOIN u.userInstance dt
                WHERE u.email = :email 
                AND dt.supportRole != :roles' 
            )
            ->setParameter('email', $username)
            ->setParameter('roles', 4);

        return $query->getOneOrNullResult();
    }
    
    public function getSupportGroups(Request $request = null)
    {
        $queryBuilder = $this->getEntityManager()->createQueryBuilder()
            ->select('supportGroup.id, supportGroup.name')->from('UVDeskCoreBundle:SupportGroup', 'supportGroup')
            ->where('supportGroup.isActive = :isActive')->setParameter('isActive', true);

        if ($request) {
            $queryBuilder
                ->andWhere("supportGroup.name LIKE :groupName")->setParameter('groupName', '%' . urldecode($request->query->get('query')) . '%')
                ->andWhere("supportGroup.id NOT IN (:ids)")->setParameter('ids', explode(',', urldecode($request->query->get('not'))));
        }

        return $queryBuilder->getQuery()->getArrayResult();
    }

    public function getSupportTeams(Request $request = null)
    {
        $queryBuilder = $this->getEntityManager()->createQueryBuilder()
            ->select('supportTeam.id, supportTeam.name')->from('UVDeskCoreBundle:SupportTeam', 'supportTeam')
            ->where('supportTeam.isActive = :isActive')->setParameter('isActive', true);
        
        if ($request) {
            $queryBuilder
                ->andWhere("supportTeam.name LIKE :subGroupName")->setParameter('subGroupName', '%' . urldecode($request->query->get('query')) . '%')
                ->andWhere("supportTeam.id NOT IN (:ids)")->setParameter('ids', explode(',',urldecode($request->query->get('not'))));
        }

        return $queryBuilder->getQuery()->getResult();
    }
    
    public function getUserSupportGroupReferences(User $user)
    {
        $query = $this->getEntityManager()->createQueryBuilder()
            ->select('ug.id')->from('UVDeskCoreBundle:User', 'u') 
            ->leftJoin('u.userInstance','userInstance')
            ->leftJoin('userInstance.supportGroups','ug')
            ->andwhere('u.id = :userId')
            ->setParameter('userId', $user->getId())
            ->andwhere('ug.isActive = 1');

        return array_map('current', $query->getQuery()->getResult());
    }

    public function getUserSupportTeamReferences(User $user)
    {
        $query = $this->getEntityManager()->createQueryBuilder()
            ->select('ut.id')->from('UVDeskCoreBundle:User', 'u')
            ->leftJoin('u.userInstance','userInstance')
            ->leftJoin('userInstance.supportTeams','ut')
            ->andwhere('u.id = :userId')
            ->andwhere('userInstance.supportRole != :agentRole')
            ->andwhere('ut.isActive = 1')
            ->setParameter('userId', $user->getId())
            ->setParameter('agentRole', '4');

        return array_map('current', $query->getQuery()->getResult());
    }
}
