<?php namespace components\message_board\classes; if(!defined('MK')) die('No direct access.');

class FeedReader
{
  
  protected
    $feed;
  
  public function __construct($feed_id)
  {
    
    //Gets the feed model.
    $this->feed = mk('Sql')->table('message_board', 'Feeds')
      ->pk($feed_id)
      ->execute_single()
      ->is('empty', function(){
        throw new \exception\NotFound('No feed with this ID.');
      });
    
  }
  
  /**
   * Fetches the feed messages in their specified priority and ordering.
   * @param  boolean $update Whether or not to update the sources first.
   * @return array A set of messages.
   */
  public function fetch($update=true)
  {
    
    //When requested, runs an update first.
    if($update === true)
      $this->update();
    
    //Collect the set of messages to work with per source.
    $messages_per_source = array();
    foreach($this->feed->sources as $source){
      
      //Gets the messages for this source.
      $messages_per_source[$source->id->get()] = mk('Sql')->table('message_board', 'Messages')
        ->where('feed_source_id', $source->id)
        ->order('dt_posted', 'DESC')
        ->execute()
        ->get('array');
      
    }
    
    //Merge the results based on the selected feed priority.
    $message_set = array();
    switch($this->feed->feed_priority->get('string')){
      
      case 'CHRONOLOGICAL':
        
        for($size = 0; $size < $this->feed->max_items->get('int'); $size++){
          
          //The index of the pile that has the most recent message.
          $latest = null;
          
          foreach($messages_per_source as $index => $pile){
            
            //If this source has no (more) messages, skip this source.
            if(count($pile) == 0)
              continue;
            
            //If there is no latest message yet, set it for sure.
            if($latest === null)
              $latest = $index;
            
            //Otherwise compare the best known latest with the current pile.
            else{
              
              $best_known = $messages_per_source[$latest][0];
              $current = $pile[0];
              
              if(strtotime($current->dt_posted->get('string')) > strtotime($best_known->dt_posted->get('string')))
                $latest = $index;
              
            }
            
          }
          
          //Check if we found anything at all.
          if($latest === null)
            break; //End of the for-loop.
          
          //If we did, move the message from the pile to the message set.
          $message_set[] = array_shift($messages_per_source[$latest]);
          
        }
        
        break;
      
      case 'ROUND_ROBIN':
        
        //See what our modulus should be.
        $mod = count($messages_per_source);
        
        //Shift the messages in order.
        for($size = 0; $size < $this->feed->max_items->get('int'); $size++){
          $message_set[] = array_shift($messages_per_source[$size % $mod]);
        }
        
        break;
      
    }
    
    //Now order this message set the way it should be.
    $messages = array();
    switch($this->feed->message_order->get('string')){
      
      case 'RANDOM':
        shuffle($message_set);
        $messages = $message_set;
        break;
      
      case 'ROUND_ROBIN':
        
        //When we have round robin priority, the order should be correct already.
        if($this->feed->feed_priority->get('string') == 'ROUND_ROBIN')
          break;
        
        //First make a collection of messages per source.
        $message_sets_per_source = array();
        foreach($message_set as $message)
          $message_sets_per_source[$message->feed_source_id->get('int')] = $message;
        
        //Than round robin over them into the final set.
        $items = count($message_set);
        for($size = 0; $size < $items; $size++){
          
          //Find the next source.
          $target = $size % count($message_sets_per_source);
          
          //Shift a message.
          $messages[] = array_shift($message_sets_per_source[$target]);
          
          //Remove the source when it's depleted.
          if(count($target) == 0)
            array_splice($message_sets_per_source, $target, 1);
          
        }
        
        break;
        
      case 'CHRONOLOGICAL':
        
        //When we have chronological priority, the order should be correct already.
        if($this->feed->feed_priority->get('string') == 'CHRONOLOGICAL')
          break;
        
        //In other cases we need to sort it ourselves.
        usort($message_set, function($a, $b){
          
          $ta = strtotime($a->dt_posted->get('string'));
          $tb = strtotime($b->dt_posted->get('string'));
          
          if($ta == $tb)
            return 0;
          
          if($ta > $tb)
            return 1;
          
          else
            return -1;
          
        });
        
        $messages = $message_set;
        
        break;
      
    }
    
    return $messages;
    
  }
  
  /**
   * Checks for new messages with each source and limits the database size to (feed:max_items) per feed source.
   * @return void
   */
  public function update()
  {
    
    foreach($this->feed->sources as $source){
      
      switch($source->type->get()){
        
        case 'TWITTER_TIMELINE':
          
          $new_messages = json_decode(
            mk('Component')
              ->helpers('api_cache')
              ->call('access_service', array('name' => 'twitter-1.1'))
              ->user_timeline(array(
                'screen_name' => $source->query->get(),
                'since_id' => $source->latest_message->remote_id->get(),
                'count' => $this->feed->max_items->get()
              ))
              ->get('string')
          );
          
          if(count($new_messages) > 0){
            
            foreach($new_messages as $message){
              
              $mmodel = mk('Sql')->model('message_board', 'Messages')
                ->set(array(
                  'feed_source_id' => $source->id
                  #TODO: rest invullen en parsen enzo
                ))
              
            }
            
          }
          
          break;
        
      }
      
      #TODO: Twitter search source
      
    }
    
  }
  
}