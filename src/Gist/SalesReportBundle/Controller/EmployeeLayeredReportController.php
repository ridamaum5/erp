<?php

namespace Gist\SalesReportBundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Gist\TemplateBundle\Model\BaseController as Controller;
use Gist\TemplateBundle\Model\RouteGenerator as RouteGenerator;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Gist\LocationBundle\LocationRegion;
use DateTime;
use ReflectionClass;

class EmployeeLayeredReportController extends Controller
{
    protected $repo;
    protected $base_view;
    protected $route_gen;

    public function __construct()
    {
        $this->route_prefix = 'gist_layered_sales_report_employee';
        $this->title = 'Layered Report - Employees';

        $this->list_title = 'Layered Report - Employees';
        $this->list_type = 'static';
    }

    // FOR TOP LAYER
    public function indexAction($date_from = null, $date_to = null, $position = null)
    {
        $data = $this->getRequest()->request->all();
        $this->route_prefix = 'gist_layered_sales_report_employee';
        $params = $this->getViewParams('List');
        $this->getControllerBase();

        //PARAMS
        $params['position'] = $position;

        if (isset($data['date_from']) && isset($data['date_to'])) {
            $date_from = DateTime::createFromFormat('Ymd', $data['date_from']);
            $date_to = DateTime::createFromFormat('Ymd', $data['date_to']);
            $params['date_from'] = $date_from->format("m/d/Y");
            $params['date_to'] = $date_to->format("m/d/Y");
            $params['all_data'] = $this->getAllData($date_from->format('Y-m-d'), $date_to->format('Y-m-d'));
            $params['date_from_url'] = $date_from->format("m-d-Y");
            $params['date_to_url'] = $date_to->format("m-d-Y");
            $params['all_data'] = $this->getAllData($date_from->format('Y-m-d'), $date_to->format('Y-m-d'));
        } else {
            if ($date_from != null) {
                $date_from = DateTime::createFromFormat('m-d-Y', $date_from);
                $date_to = DateTime::createFromFormat('m-d-Y', $date_to);
                $date_from_twig = $date_from->format("m/d/Y");
                $date_to_twig = $date_to->format("m/d/Y");
                $params['date_from_url'] = $date_from->format("m-d-Y");
                $params['date_to_url'] = $date_to->format("m-d-Y");
                $params['all_data'] = $this->getAllData($date_from->format('Y-m-d'), $date_to->format('Y-m-d'));
            } else {
                $date_from = new DateTime();
                $date_to = new DateTime();
                $date_from_twig = $date_from->format("m/01/Y");
                $date_to_twig = $date_to->format("m/t/Y");
                $params['date_from_url'] = $date_from->format("m-01-Y");
                $params['date_to_url'] = $date_to->format("m-t-Y");
                $params['all_data'] = $this->getAllData($date_from->format('Y-m-01'), $date_to->format('Y-m-t'));
            }

            $params['date_from'] = $date_from_twig;
            $params['date_to'] = $date_to_twig;


        }


        return $this->render('GistSalesReportBundle:EmployeeLayered:index.html.twig', $params);
    }

    protected function getAllData($date_from, $date_to)
    {
        $em = $this->getDoctrine()->getManager();
        $layeredReportService = $this->get('gist_layered_report_service');
        $data = $layeredReportService->getTransactions($date_from, $date_to, null, null);

        $total_payments = 0;
        $total_cost = 0;
        $total_profit = 0;

        foreach ($data as $d) {
            if (!$d->hasChildLayeredReport()) {
                $total_payments += $d->getTransactionTotal();

                foreach ($d->getItems() as $item) {
                    if (!$item->getReturned()) {
                        $product = $em->getRepository('GistInventoryBundle:Product')->findOneById($item->getProductId());
                        $total_cost += $product->getCost();
                    }
                }
            }
        }

        $total_profit = $total_payments - $total_cost;

        return [
            'total_sales' => number_format($total_payments, 2, '.',','),
            'total_cost' => number_format($total_cost, 2, '.',','),
            'total_profit' => number_format($total_profit, 2, '.',','),
        ];
    }
    //END TOP LAYER

    //FOR POSITIONS/L2
    public function positionsIndexAction($date_from = null, $date_to = null, $position = null)
    {
        $em = $this->getDoctrine()->getManager();
        try {
            $data = $this->getRequest()->request->all();
            $this->route_prefix = 'gist_layered_sales_report_employee';
            $params = $this->getViewParams('List');
            $this->getControllerBase();

            //PARAMS
            $params['position'] = $position;

            if (DateTime::createFromFormat('m-d-Y', $date_from) !== false && DateTime::createFromFormat('m-d-Y', $date_to) !== false) {
                $date_from = DateTime::createFromFormat('m-d-Y', $date_from);
                $date_to = DateTime::createFromFormat('m-d-Y', $date_to);
                $params['date_from'] = $date_from->format("m/d/Y");
                $params['date_to'] = $date_to->format("m/d/Y");
                $params['positions_data'] = $this->getPositionsData($date_from->format('Y-m-d'), $date_to->format('Y-m-d'));
                $params['date_from_url'] = $date_from->format("m-d-Y");
                $params['date_to_url'] = $date_to->format("m-d-Y");



                return $this->render('GistSalesReportBundle:EmployeeLayered:positions.html.twig', $params);

            } else {
                return $this->redirect($this->generateUrl('gist_layered_sales_report_product_index'));
            }
        } catch (Exception $e) {
            return $this->redirect($this->generateUrl('gist_layered_sales_report_product_index'));
        }
    }

    protected function getPositionsData($date_from, $date_to)
    {
        $list_opts = [];
        $em = $this->getDoctrine()->getManager();
        //get all positions
        $salesDept = $em->getRepository('GistUserBundle:Department')->findOneBy(['department_name'=>'Sales']);
        $adminDept = $em->getRepository('GistUserBundle:Department')->findOneBy(['department_name'=>'Administrative']);
        $allPositions = $em->getRepository('GistUserBundle:Group')->findBy(['department'=>[$salesDept->getID(),$adminDept->getID()]]);


        foreach ($allPositions as $position) {
            //initiate totals
            $positionId = $position->getID();
            $totalSales = 0;
            $totalCost = 0;
            $transactionIds = array();

            //get all transaction items based on date filter
            $layeredReportService = $this->get('gist_layered_report_service');
            $transactionItems = $layeredReportService->getTransactionItems($date_from, $date_to, null, null);

            //loop items and check if item's brand is the current loop's brand then add the cost
            foreach ($transactionItems as $transactionItem) {
                if (!$transactionItem->getTransaction()->hasChildLayeredReport() && !$transactionItem->getReturned()) {
                    $user = $em->getRepository('GistUserBundle:User')->findOneById($transactionItem->getTransaction()->getUserCreate()->getID());
                    if ($user->getGroup()->getID() == $positionId) {
                        $totalSales += $transactionItem->getTotalAmount();
                    }
                }
            }

            $brandTotalProfit = $totalSales - $totalCost;
            if ($totalSales > 0) {
                $list_opts[] = array(
                    'date_from' => $date_from,
                    'date_to' => $date_to,
                    'position_id' => $positionId,
                    'position_name' => $position->getName(),
                    'total_sales' => number_format($totalSales, 2, '.', ','),
                    'total_cost' => number_format($totalCost, 2, '.', ','),
                    'total_profit' => number_format($brandTotalProfit, 2, '.', ','),
                );
            }
        }

        if (count($allPositions) > 0) {
            return $list_opts;
        } else {
            return null;
        }
    }
    //END POSITIONS/L2
    //FOR EMPLOYEES/L3 / SHOW EMPLOYEES
    public function employeesIndexAction($date_from = null, $date_to = null, $position = null)
    {
        $em = $this->getDoctrine()->getManager();

        try {
            $data = $this->getRequest()->request->all();
            $this->route_prefix = 'gist_layered_sales_report_employee';
            $params = $this->getViewParams('List');
            $this->getControllerBase();
            $params['position '] = $position ;

            if (DateTime::createFromFormat('m-d-Y', $date_from) !== false && DateTime::createFromFormat('m-d-Y', $date_to) !== false) {
                $date_from = DateTime::createFromFormat('m-d-Y', $date_from);
                $date_to = DateTime::createFromFormat('m-d-Y', $date_to);
                $params['date_from'] = $date_from->format("m/d/Y");
                $params['date_to'] = $date_to->format("m/d/Y");
                $params['employees_data'] = $this->getEmployeesData($date_from->format('Y-m-d'), $date_to->format('Y-m-d'), $position);
                $params['date_from_url'] = $date_from->format("m-d-Y");
                $params['date_to_url'] = $date_to->format("m-d-Y");
                $positionObject = $em->getRepository('GistUserBundle:Group')->findOneById($position);
                $params['position_id'] = $positionObject->getID();
                $params['position_name'] = $positionObject->getName();

                return $this->render('GistSalesReportBundle:EmployeeLayered:employees.html.twig', $params);

            } else {
                return $this->redirect($this->generateUrl('gist_layered_sales_report_product_index'));
            }


        } catch (Exception $e) {
            return $this->redirect($this->generateUrl('gist_layered_sales_report_product_index'));
        }
    }

    protected function getEmployeesData($date_from, $date_to, $position)
    {
        $list_opts = [];
        $em = $this->getDoctrine()->getManager();
        $allEmployees = $em->getRepository('GistUserBundle:User')->findBy(['group'=>$position]);

        foreach ($allEmployees as $employee) {
            $employeeId = $employee->getID();
            $totalSales = 0;
            $totalCost = 0;
            $layeredReportService = $this->get('gist_layered_report_service');
            $transactionItems = $layeredReportService->getTransactionItems($date_from, $date_to, null, null);

            foreach ($transactionItems as $transactionItem) {
                if (!$transactionItem->getTransaction()->hasChildLayeredReport() && !$transactionItem->getReturned()) {
                    $employeex = $em->getRepository('GistUserBundle:User')->findOneById($transactionItem->getTransaction()->getUserCreate()->getID());
                    if ($employeex->getID() == $employeeId && $employeex->getGroup()->getID() == $position) {
                        $totalSales += $transactionItem->getTotalAmount();
                    }
                }
            }

            $brandTotalProfit = $totalSales - $totalCost;

            if ($totalSales > 0) {
                $list_opts[] = array(
                    'date_from' => $date_from,
                    'date_to' => $date_to,
                    'employee_id' => $employeeId,
                    'position_id' => $position,
                    'employee_name' => $employee->getDisplayName(),
                    'total_sales' => number_format($totalSales, 2, '.', ','),
                    'total_cost' => number_format($totalCost, 2, '.', ','),
                    'total_profit' => number_format($brandTotalProfit, 2, '.', ','),
                );
            }
        }

        if (count($allEmployees) > 0) {
            return $list_opts;
        } else {
            return null;
        }
    }
    //END AREAS/L3

    protected function getRouteGen()
    {
        if ($this->route_gen == null)
            $this->route_gen = new RouteGenerator($this->route_prefix);

        return $this->route_gen;
    }

    protected function getViewParams($subtitle = '', $selected = null)
    {
        if ($selected == null && $this->getRouteGen()->getList() != null)
            $selected = $this->getRouteGen()->getList();

        $params = parent::getViewParams($subtitle, $selected);
        $params['route_list'] = $this->getRouteGen()->getList();
        $params['route_add'] = $this->getRouteGen()->getAdd();
        $params['route_edit'] = $this->getRouteGen()->getEdit();
        $params['route_delete'] = $this->getRouteGen()->getDelete();
        $params['route_grid'] = $this->getRouteGen()->getGrid();
        $params['list_title'] = $this->list_title;
        $params['prefix'] = $this->route_prefix;

        $params['base_view'] = $this->base_view;
        return $params;
    }

    protected function getControllerBase()
    {
        $full = $this->getRequest()->get('_controller');
        $x_full = explode('\\', $full);

        $bundle = $x_full[0] . $x_full[1];
        $x_cont = explode('Controller:', $x_full[3]);
        $name = $x_cont[0];

        $base = $bundle . ':' . $name;

        if ($this->repo == null)
            $this->repo = $base;
        $this->base_view = $base;

        return $base;
    }

    //LAST NEW LAYER
    public function transactionsIndexAction($date_from = null, $date_to = null, $position = null, $employee = null)
    {
        $em = $this->getDoctrine()->getManager();
        try {
            $data = $this->getRequest()->request->all();
            $this->route_prefix = 'gist_layered_sales_report_employee';
            $params = $this->getViewParams('List');
            $this->getControllerBase();

            $positionObject = $em->getRepository('GistUserBundle:Group')->findOneById($position);
            $params['position_id'] = $positionObject->getID();
            $params['position_name'] = $positionObject->getName();

            $employeeObject = $em->getRepository('GistUserBundle:User')->findOneById($employee);
            $params['employee_id'] = $employeeObject->getID();
            $params['employee_name'] = $employeeObject->getDisplayName();

            if (DateTime::createFromFormat('m-d-Y', $date_from) !== false && DateTime::createFromFormat('m-d-Y', $date_to) !== false) {
                $date_from = DateTime::createFromFormat('m-d-Y', $date_from);
                $date_to = DateTime::createFromFormat('m-d-Y', $date_to);
                $params['date_from'] = $date_from->format("m/d/Y");
                $params['date_to'] = $date_to->format("m/d/Y");
                $params['data'] = $this->getProductTransactionsData($date_from->format('Y-m-d'), $date_to->format('Y-m-d'), $position, $employee);
                $params['date_from_url'] = $date_from->format("m-d-Y");
                $params['date_to_url'] = $date_to->format("m-d-Y");
                return $this->render('GistSalesReportBundle:EmployeeLayered:transactions.html.twig', $params);

            } else {
                return $this->redirect($this->generateUrl('gist_layered_sales_report_product_index'));
            }


        } catch (Exception $e) {
            return $this->redirect($this->generateUrl('gist_layered_sales_report_product_index'));
        }
    }

    protected function getProductTransactionsData($date_from, $date_to, $position, $employee)
    {
        $list_opts = [];
        $em = $this->getDoctrine()->getManager();
        $layeredReportService = $this->get('gist_layered_report_service');
        $allTransactions = $layeredReportService->getTransactions($date_from, $date_to, null, null);

        $positionObject = $em->getRepository('GistUserBundle:Group')->findOneById($position);
        $params['position_id'] = $positionObject->getID();
        $params['position_name'] = $positionObject->getName();

        $employeeObject = $em->getRepository('GistUserBundle:User')->findOneById($employee);
        $params['employee_id'] = $employeeObject->getID();
        $params['employee_name'] = $employeeObject->getDisplayName();

        foreach ($allTransactions as $transaction) {
            if ($transaction->getUserCreate()->getID() == $employee) {
                if (!$transaction->hasChildLayeredReport()) {
                    $transactionId = $transaction->getTransDisplayIdFormatted();
                    $totalSales = 0;
                    $totalCost = 0;
                    $transactionItems = $transaction->getItems();

                    //loop items and check if item's brand is the current loop's brand then add the cost
                    foreach ($transactionItems as $transactionItem) {
                        if (!$transactionItem->getReturned()) {
                            $totalSales += $transactionItem->getTotalAmount();
                        }
                    }

                    $brandTotalProfit = $totalSales - $totalCost;
                    //$posObject = $em->getRepository('GistLocationBundle:POSLocations')->findOneById($transaction);
                    if ($totalSales > 0) {
                        $list_opts[] = array(
                            'date_from' => $date_from,
                            'date_to' => $date_to,
                            'transaction_pos_name' => $transaction->getPOSLocation()->getName(),
                            'transaction_date' => $transaction->getDateCreate()->format('F d, Y h:i A'),
                            'transaction_id' => $transactionId,
                            'transaction_system_id' => $transaction->getID(),
//                            'pos_name' => $posObject->getName(),
//                            'pos_id' => $posObject->getID(),
                            'total_sales' => number_format($totalSales, 2, '.', ','),
                            'total_cost' => number_format($totalCost, 2, '.', ','),
                            'total_profit' => number_format($brandTotalProfit, 2, '.', ','),
                            'position_id' => $positionObject->getID(),
                            'position_name' => $positionObject->getName(),
                            'employee_id' => $employeeObject->getID(),
                            'employee_name' => $employeeObject->getDisplayName()
                        );
                    }
                }
            }
        }

        if (count($allTransactions) > 0) {
            return $list_opts;
        } else {
            return null;
        }
    }

    public function viewTransactionDetailsAction($date_from, $date_to, $id, $position, $employee)
    {
        $em = $this->getDoctrine()->getManager();
        $obj = $em->getRepository('GistPOSERPBundle:POSTransaction')->find($id);
        $session = $this->getRequest()->getSession();
        $session->set('csrf_token', md5(uniqid()));
        $params = $this->getViewParams('Edit');
        $params['object'] = $obj;
        $params['customer_name'] = $obj->getCustomer()->getNameFormatted();
        $params['customer_creator'] = $obj->getCustomer()->getUserCreate()->getName();
        $params['customer'] = $obj->getCustomer();
        $params['readonly'] = true;

        $positionObject = $em->getRepository('GistUserBundle:Group')->findOneById($position);
        $params['position_id'] = $positionObject->getID();
        $params['position_name'] = $positionObject->getName();

        $employeeObject = $em->getRepository('GistUserBundle:User')->findOneById($employee);
        $params['employee_id'] = $employeeObject->getID();
        $params['employee_name'] = $employeeObject->getDisplayName();

        if (DateTime::createFromFormat('m-d-Y', $date_from) !== false && DateTime::createFromFormat('m-d-Y', $date_to) !== false) {
            $date_from = DateTime::createFromFormat('m-d-Y', $date_from);
            $date_to = DateTime::createFromFormat('m-d-Y', $date_to);
            $params['date_from'] = $date_from->format("m/d/Y");
            $params['date_to'] = $date_to->format("m/d/Y");
            $params['data'] = $this->getProductTransactionsData($date_from->format('Y-m-d'), $date_to->format('Y-m-d'), $position, $employee);
            $params['date_from_url'] = $date_from->format("m-d-Y");
            $params['date_to_url'] = $date_to->format("m-d-Y");

            $positionObject = $em->getRepository('GistUserBundle:Group')->findOneById($position);
            $params['position_id'] = $positionObject->getID();
            $params['position_name'] = $positionObject->getName();

            $employeeObject = $em->getRepository('GistUserBundle:User')->findOneById($employee);
            $params['employee_id'] = $employeeObject->getID();
            $params['employee_name'] = $employeeObject->getDisplayName();

            return $this->render('GistSalesReportBundle:EmployeeLayered:transaction_details.html.twig', $params);

        } else {
            return $this->redirect($this->generateUrl('gist_layered_sales_report_product_index'));
        }
    }
}
