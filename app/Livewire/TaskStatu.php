<?php

namespace App\Livewire;

use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Admin\Factory;
use App\Models\Planning\Task;
use App\Events\TaskChangeStatu;
use App\Models\Planning\Status;
use App\Models\Products\StockMove;
use Illuminate\Support\Facades\Auth;
use App\Models\Planning\TaskActivities;
use App\Models\Quality\QualityNonConformity;

class TaskStatu extends Component
{
    use WithPagination;
    protected $paginationTheme = 'bootstrap';
    
    public $Task;

    public $taskStockMoves;
    public $taskActivities;
    public $lastTaskActivities;

    public $search = '';
    public $user_id ;

    public $timelineData = [];


    public $addGoodQt = 0;
    public $addBadQt = 0;
    public $not_recalculate = true;
    private $RecalculateBooleanValue = 0;
    public $end_date;

    // Validation Rules
    protected $rules = [
        'addGoodQt' =>'required|numeric|min:0',
        'addBadQt' =>'required|numeric|min:0',
    ];

    public function mount($id) 
    {
        $this->user_id = Auth::id();
        $this->search = $id;
        $this->taskStockMoves = StockMove::where('task_id', $this->search)->get();
        $this->lastTaskActivities = TaskActivities::where('task_id', $this->search)->latest()->first();
        $this->taskActivities = TaskActivities::where('task_id', $this->search)->get();
        $this->Task = Task::with('OrderLines.order')->find($this->search);
       // $this->end_date = $this->Task->end_date;

        // Organiser les données pour la timeline
        $this->timelineData = [];

        foreach ($this->taskStockMoves as $taskStockMove){
            $this->timelineData[] = [
                'date' => $taskStockMove->created_at->format('d M Y'),
                'icon' => 'fas fa-list  bg-primary',
                'content' => __('general_content.new_entry_stock_trans_key') .' x'. $taskStockMove->qty .'- '. __('general_content.purchase_order_reception_trans_key'),
                'details' => $taskStockMove->GetPrettyCreatedAttribute(),
            ];
        }

        foreach ($this->taskActivities as $taskActivitie){
            if($taskActivitie->type == 1){
                $class = '';
                $content =  $taskActivitie->user->name .' - '. __('general_content.set_to_start_trans_key');
            }
            elseif ($taskActivitie->type == 2){
                $class = 'primary';
                $content =  $taskActivitie->user->name .' - '. __('general_content.set_to_end_trans_key');
            }
            elseif ($taskActivitie->type == 3){
                $class = 'info';
                $content =  $taskActivitie->user->name .' - '. __('general_content.set_to_finish_trans_key');
            }
            elseif ($taskActivitie->type == 4){
                $class = 'success';
                $content =  $taskActivitie->user->name .' - '. __('general_content.declare_finish_trans_key') .' '. $taskActivitie->good_qt .' '.  __('general_content.part_trans_key');
            }
            elseif ($taskActivitie->type == 5){
                $class = 'danger';
                $content =  $taskActivitie->user->name .' - '. __('general_content.declare_rejected_trans_key') .' '. $taskActivitie->bad_qt .' '. __('general_content.part_trans_key');
            }

            $this->timelineData[] = [
                'date' => $taskActivitie->created_at->format('d M Y'),
                'icon' => 'fas fa-calendar-alt  bg-'. $class,
                'content' => $content,
                'details' => $taskActivitie->GetPrettyCreatedAttribute(),
            ];
        }


        // Ajouter une commande s'il y en a
        if(!is_null($this->Task)){
            foreach ($this->Task->purchaseLines as $purchaseLine) {

                $this->timelineData[] = [
                    'date' => $purchaseLine->created_at->format('d M Y'),
                    'icon' => 'fas fa-calendar-alt  bg-success',
                    'content' => __('general_content.purchase_trans_key') ." ". $purchaseLine->purchase->code ." - ".  __('general_content.qty_reciept_trans_key') .":". $purchaseLine->receipt_qty ."/". $purchaseLine->qty ,
                    'details' => $purchaseLine->GetPrettyCreatedAttribute(),
                ];

                if (!is_null($purchaseLine->purchaseReceiptLines)) {
                    foreach ($purchaseLine->purchaseReceiptLines as $receipt) {
                        $this->timelineData[] = [
                            'date' => $receipt->created_at->format('d M Y'),
                            'icon' => 'fas fa-calendar-alt  bg-warning',
                            'content' => __('general_content.po_receipt_trans_key') ." " . $receipt->label ." - ".  __('general_content.qty_reciept_trans_key') .":". $receipt->receipt_qty,
                            'details' => $receipt->GetPrettyCreatedAttribute(),
                        ];
                    }
                }
            }

            // Ajouter la task initiale
            $this->timelineData[] = [
                'date' => $this->Task->created_at->format('d M Y'),
                'icon' => 'fa fa-tags bg-primary',
                'content' => "Task créée",
                'details' => $this->Task->GetPrettyCreatedAttribute(),
            ];
            
        }

        // Trier le tableau par date
        usort($this->timelineData, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
    }

    public function render()
    {
        
        if(!empty($this->search)){
            $this->lastTaskActivities = TaskActivities::where('task_id', $this->search)->latest()->first();
            $this->taskActivities = TaskActivities::where('task_id', $this->search)->get();
            $this->Task = Task::with('OrderLines.order')->find($this->search);
        }
        
        return view('livewire.task-statu', [
            'Task' => $this->Task,
            'taskActivities' => $this->taskActivities,
            'lastTaskActivities' => $this->lastTaskActivities,
        ]);
    }

    public function StartTimeTask($taskId)
    {
        // Create Line
        TaskActivities::create([
            'task_id'=> $taskId,
            'user_id'=>$this->user_id,
            'type'=>'1',
            'timestamp' =>Carbon::now(),
            'comment'=>'',
        ]);

        $StatusUpdate = Status::select('id')->where('title', 'In progress')->first();

        /* // update task statu on Kanban*/
        if($StatusUpdate->id){
            $Task = Task::where('id',$taskId)->update(['status_id'=>$StatusUpdate->id]);
            event(new TaskChangeStatu($taskId));
        }

        $this->render();

        // Set Flash Message
        session()->flash('success','Log activitie added successfully');
    }

    public function EndTimeTask($taskId)
    {
        // Create Line
        TaskActivities::create([
            'task_id'=> $taskId,
            'user_id'=>$this->user_id,
            'type'=>'2',
            'timestamp' =>Carbon::now(),
            'comment'=>'',
        ]);

        $this->render();

        // Set Flash Message
        session()->flash('success','Log activitie added successfully');
    }
    
    public function EndTask($taskId)
    {
        // Create Line
        TaskActivities::create([
            'task_id'=> $taskId,
            'user_id'=>$this->user_id,
            'type'=>'3',
            'timestamp' =>Carbon::now(),
            'comment'=>'',
        ]);
        $StatusUpdate = Status::select('id')->where('title', 'Finished')->first();

        /* // update task statu on Kanban*/
        if($StatusUpdate->id){
            $Task = Task::where('id',$taskId)->update(['status_id'=>$StatusUpdate->id]);
            event(new TaskChangeStatu($taskId));
        }

        $this->render();

        // Set Flash Message
        session()->flash('success','Log activitie added successfully');
    }

    public function addGoodQt()
    {
        $this->validate();
        // Create Line
        TaskActivities::create([
            'task_id'=> $this->search,
            'user_id'=>$this->user_id,
            'type'=>'4',
            'good_qt'=>$this->addGoodQt,
            'comment'=>'',
        ]);

        $this->render();

        // Set Flash Message
        session()->flash('success','Log activitie added successfully');
    }

    public function addRejectedQt()
    {
        $this->validate();
        // Create Line
        TaskActivities::create([
            'task_id'=> $this->search,
            'user_id'=>$this->user_id,
            'type'=>'5',
            'bad_qt'=>$this->addBadQt,
            'comment'=>'',
        ]);

        $this->render();

        // Set Flash Message
        session()->flash('success','Log activitie added successfully');
    }

    public function updateDateTask(){
        if($this->not_recalculate) $this->RecalculateBooleanValue = 1;
        Task::find($this->search)->fill([
            'not_recalculate'=>$this->RecalculateBooleanValue,
            'end_date'=>$this->end_date,
        ])->save();

        session()->flash('success','Date Updated Successfully');
    }

    public function createNC($id, $companie_id, $id_service){
        $NewNonConformity = QualityNonConformity::create([
            'code'=> "NC-TASK-#". $id,
            'label'=>"NC-TASK-#". $id,
            'statu'=>1,
            'type'=>1,
            'user_id'=>Auth::id(),
            'methods_services_id' =>$id_service,
            'companie_id'=>$companie_id,
            'task_id'=>$id,
        ]);

return redirect()->route('quality')->with('success', 'Successfully created non conformitie.');
}
}
