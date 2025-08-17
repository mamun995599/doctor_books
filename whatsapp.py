from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.common.action_chains import ActionChains
import time
from datetime import datetime
import re
import requests
import subprocess
import os
import websocket
import json

# Path to your ChromeDriver
chrome_driver_path = 'chromedriver.exe'
chrome_user_data_dir = 'C:/Users/MAMUN/AppData/Local/Google/Chrome for Testing/User Data'
chrome_path = "C:/Program Files/Google/Chrome/Application/chrome.exe"

# WebSocket server configuration
WS_HOST = 'localhost'
WS_PORT = 8090

# Check if Chrome is already running with debugging
def is_chrome_running():
    try:
        response = requests.get("http://localhost:9222/json", timeout=1)
        return response.status_code == 200
    except:
        return False

# Start Chrome with remote debugging if not running
if not is_chrome_running():
    print("Starting Chrome with remote debugging...")
    subprocess.Popen([
        chrome_path,
        "--remote-debugging-port=9222",
        f"--user-data-dir={chrome_user_data_dir}",
        "about:blank"
    ])
    time.sleep(5)  # Wait for Chrome to start

# Connect to Chrome with remote debugging
chrome_options = webdriver.ChromeOptions()
chrome_options.add_experimental_option("debuggerAddress", "localhost:9222")
driver = webdriver.Chrome(service=Service(chrome_driver_path), options=chrome_options)

print("Please make sure you're already in the correct WhatsApp group chat...")
time.sleep(5)  # Give user time to ensure they're in the right group

# Send data to WebSocket server and get doctor name and serial number
def send_to_websocket_server(fields):
    try:
        # Create WebSocket connection
        ws = websocket.create_connection(f"ws://{WS_HOST}:{WS_PORT}")
        
        # Step 1: Send save_row message
        save_message = {
            "type": "save_row",
            "data": {
                "doctor_id": int(fields["doctor_id"]),  # Convert to integer
                "date": fields["date"],
                "entry": {
                    "name": fields["patient_name"],
                    "age": fields.get("age", ""),  # Include age if provided
                    "phone": fields["phone_number"],
                    "doctor_id": int(fields["doctor_id"]),  # Convert to integer
                    "ref_doctor_name": fields["ref_doctor_name"] or "",
                    "marketting_officer": fields["marketing_officer_name"] or "",
                    "date": fields["date"]
                }
            }
        }
        ws.send(json.dumps(save_message))
        print("Save message sent")
        
        # Step 2: Request doctor information
        doctor_request = {
            "type": "load_doctors"
        }
        ws.send(json.dumps(doctor_request))
        print("Doctors request sent")
        
        # Wait for doctors response
        doctors_response = None
        start_time = time.time()
        timeout = 5  # 5 seconds timeout
        
        while time.time() - start_time < timeout:
            try:
                response_str = ws.recv()
                response = json.loads(response_str)
                
                # Check if this is the doctors response
                if response.get('type') == 'doctors_loaded':
                    doctors_response = response
                    break
            except websocket.WebSocketTimeoutException:
                continue
            except Exception as e:
                print(f"Error receiving doctors response: {e}")
                break
        
        # Extract doctor name
        doctor_name = f"ID:{fields['doctor_id']}"  # Default fallback
        if doctors_response and doctors_response.get('type') == 'doctors_loaded':
            doctors = doctors_response.get('data', [])
            doctor_id = int(fields["doctor_id"])
            
            # Find the doctor with matching ID
            for doctor in doctors:
                if doctor.get('doctor_id') == doctor_id:
                    doctor_name = doctor.get('doctor_name', f"ID:{doctor_id}")
                    break
        
        print(f"Doctor name: {doctor_name}")
        
        # Step 3: Request patient entries to get serial number
        load_request = {
            "type": "load",
            "data": {
                "doctor_id": int(fields["doctor_id"]),
                "date": fields["date"]
            }
        }
        ws.send(json.dumps(load_request))
        print("Load request sent")
        
        # Wait for load response
        load_response = None
        start_time = time.time()
        timeout = 5  # 5 seconds timeout
        
        while time.time() - start_time < timeout:
            try:
                response_str = ws.recv()
                response = json.loads(response_str)
                
                # Check if this is the load response
                if response.get('type') == 'load_result':
                    load_response = response
                    break
            except websocket.WebSocketTimeoutException:
                continue
            except Exception as e:
                print(f"Error receiving load response: {e}")
                break
        
        # Extract serial number from the response
        serial_number = "N/A"
        if load_response and load_response.get('type') == 'load_result':
            entries = load_response.get('data', {}).get('entries', [])
            
            # Find the entry we just saved by matching name, phone, and date
            for entry in entries:
                if (entry.get('name') == fields['patient_name'] and 
                    entry.get('phone') == fields['phone_number'] and 
                    entry.get('date') == fields['date']):
                    # Return the SL number calculated by the server
                    serial_number = entry.get('sl', 'N/A')
                    break
            
            # If not found, return the total count as fallback
            if serial_number == "N/A":
                serial_number = str(len(entries))
        
        print(f"Serial number: {serial_number}")
        
        ws.close()
        return doctor_name, serial_number
            
    except Exception as e:
        print(f"Failed to send data to WebSocket server: {e}")
        return f"ID:{fields['doctor_id']}", "N/A"

# Get the last message in the current chat
def get_last_message():
    try:
        # Try to get just the last message directly (more efficient)
        last_message_selectors = [
            "(//div[contains(@class, 'message-in') or contains(@class, 'message-out')])[last()]",
            "(//div[@data-testid='msg-container'])[last()]"
        ]
        
        message_element = None
        for selector in last_message_selectors:
            try:
                message_element = WebDriverWait(driver, 2).until(
                    EC.presence_of_element_located((By.XPATH, selector))
                )
                break
            except:
                continue
        
        if not message_element:
            print("No message found")
            return None, None, None, None
        
        # Check if this is our own message (outgoing)
        is_own_message = False
        try:
            if "message-out" in message_element.get_attribute("class"):
                is_own_message = True
        except:
            pass
        
        # Extract sender name
        sender_name = ""
        try:
            # Try multiple selectors for sender name
            sender_selectors = [
                ".//span[contains(@class, '_ahxt')]",
                ".//span[contains(@class, 'x1ypdohk')]",
                ".//div[contains(@class, 'message-in')]//div[contains(@class, '_ao3e')]/span",
                ".//div[contains(@class, 'message-in')]//div[contains(@class, 'copyable-text')]/preceding-sibling::div/span"
            ]
            
            for sender_selector in sender_selectors:
                try:
                    sender_element = message_element.find_element(By.XPATH, sender_selector)
                    sender_name = sender_element.text
                    if sender_name:
                        print(f"Extracted sender name: {sender_name}")
                        break
                except:
                    continue
        except Exception as e:
            print(f"Error extracting sender name: {e}")
        
        # Try to extract the message text
        text_selectors = [
            ".//span[contains(@class, 'selectable-text')]",
            ".//div[contains(@class, 'copyable-text')]",
            ".//div[@data-testid='message-text']"
        ]
        
        message_text = None
        for text_selector in text_selectors:
            try:
                text_element = message_element.find_element(By.XPATH, text_selector)
                message_text = text_element.text
                if message_text:
                    break
            except:
                continue
        
        if not message_text:
            message_text = message_element.text
        
        # Skip our own reply messages (but not messages with "self" keyword)
        if is_own_message and not (message_text and message_text.strip().lower().startswith("self")):
            return None, None, None, None
        
        # Skip predefined reply messages
        if message_text.startswith("Congratulations! Dear"):
            return None, None, None, None
        
        return message_text, message_element, sender_name, is_own_message
            
    except Exception as e:
        print(f"Error getting last message: {e}")
        return None, None, None, None

# Check if the message date is valid
def is_valid_date(date_string):
    try:
        datetime.strptime(date_string, '%Y-%m-%d')
        return True
    except ValueError:
        return False

# Extract message fields based on key-value format with new line separator
def extract_message_fields(message):
    fields = {
        "date": None,
        "phone_number": None,
        "patient_name": None,
        "doctor_id": None,
        "ref_doctor_name": None,
        "marketing_officer_name": None,
        "age": None  # Added age field
    }
    
    # Split message by new lines and handle different line endings
    lines = message.replace('\r\n', '\n').split('\n')
    
    print(f"Processing message with {len(lines)} lines:")
    for i, line in enumerate(lines):
        print(f"Line {i}: '{line}'")
    
    # Check if the first line is "self" and skip it
    is_self_message = False
    if lines and lines[0].strip().lower() == "self":
        lines = lines[1:]  # Remove the first line
        is_self_message = True
        print("Detected 'self' keyword, processing as self message")
    
    # Process each line for key-value pairs
    for line in lines:
        line = line.strip()
        if not line:  # Skip empty lines
            print(f"Skipping empty line")
            continue
            
        print(f"Processing line: '{line}'")
        
        # Try short key-value patterns (more flexible)
        short_patterns = {
            "date": r"dt\s*:\s*([0-9]{4}-[0-9]{2}-[0-9]{2})",
            "patient_name": r"nm\s*:\s*(.+)",
            "phone_number": r"ph\s*:\s*([0-9\+\-\s]+)",
            "doctor_id": r"did\s*:\s*(\d+)",  # Changed from dr to did and expect digits only
            "ref_doctor_name": r"ref\s*:\s*(.+)",
            "marketing_officer_name": r"mr\s*:\s*(.+)",
            "age": r"ag\s*:\s*(.+)"  # Added age pattern
        }
        
        # Also try long key-value patterns for backward compatibility
        long_patterns = {
            "date": r"date\s*:\s*([0-9]{4}-[0-9]{2}-[0-9]{2})",
            "patient_name": r"patient_name\s*:\s*(.+)",
            "phone_number": r"phone_number\s*:\s*([0-9\+\-\s]+)",
            "doctor_id": r"doctor_id\s*:\s*(\d+)",  # Changed from doctor_name to doctor_id
            "ref_doctor_name": r"ref_doctor_name\s*:\s*(.+)",
            "marketing_officer_name": r"marketing_officer_name\s*:\s*(.+)",
            "age": r"age\s*:\s*(.+)"  # Added age pattern
        }
        
        # Try short patterns first
        matched = False
        for key, pattern in short_patterns.items():
            match = re.match(pattern, line, re.IGNORECASE)
            if match:
                value = match.group(1).strip()
                fields[key] = value
                print(f"Matched {key}: {value}")
                matched = True
                break
        
        # If not matched with short patterns, try long patterns
        if not matched:
            for key, pattern in long_patterns.items():
                match = re.match(pattern, line, re.IGNORECASE)
                if match:
                    value = match.group(1).strip()
                    fields[key] = value
                    print(f"Matched {key} (long): {value}")
                    break
        
        if not matched:
            print(f"No pattern matched for line: '{line}'")
    
    print(f"Final fields: {fields}")
    return fields, is_self_message

# Send a reply message in WhatsApp
def send_reply_message(reply_text):
    try:
        # Try multiple selectors for the message input
        selectors = [
            "//div[@id='main']//footer//div[@contenteditable='true']",
            "//div[@data-testid='conversation-panel-footer']//div[@contenteditable='true']",
            "//div[contains(@class, 'copyable-text')][@contenteditable='true']"
        ]
        
        message_box = None
        for selector in selectors:
            try:
                message_box = WebDriverWait(driver, 1).until(
                    EC.element_to_be_clickable((By.XPATH, selector))
                )
                
                # Verify this is not the search bar by checking its location
                location = message_box.location
                if location['y'] < 100:
                    continue
                
                break
            except:
                continue
        
        if message_box:
            # Click on the message input to ensure focus
            message_box.click()
            
            # Clear any existing text
            message_box.clear()
            
            # Type and send the message
            message_box.send_keys(reply_text)
            message_box.send_keys(Keys.ENTER)
            print("Reply sent successfully")
            
            # Add a delay to prevent immediately detecting our own reply
            time.sleep(2)  # Increased delay
        else:
            print("Could not find message input box")
    except Exception as e:
        print(f"Error sending reply: {e}")

try:
    # Initialize with the current last message to avoid processing existing messages
    print("Initializing with current last message...")
    last_message_seen, _, _, _ = get_last_message()
    if last_message_seen:
        print(f"Initial last message: {last_message_seen}")
    else:
        last_message_seen = ""
    
    # Flag to track if we just sent a reply
    just_sent_reply = False
    reply_text_sent = ""
    
    while True:
        # If we just sent a reply, wait a bit longer and reset the flag
        if just_sent_reply:
            time.sleep(3)  # Wait longer after sending a reply
            just_sent_reply = False
            print("Resuming message checking after sending reply")
            continue
        
        last_message, last_message_element, sender_name, is_own_message = get_last_message()
        
        if last_message and last_message != last_message_seen:
            last_message_seen = last_message
            print(f"New message from {sender_name}: {last_message}")
                
            fields, is_self_message = extract_message_fields(last_message)
            
            # If it's a self message, set sender_name to "Self"
            if is_self_message:
                sender_name = "Self"
                print("Message identified as self message, setting sender to 'Self'")
            
            # Check required fields: date, patient_name, phone_number, and doctor_id
            if (fields["date"] and is_valid_date(fields["date"]) and 
                fields["patient_name"] and fields["phone_number"] and fields["doctor_id"]):
                
                # Send data to WebSocket server and get doctor name and serial number
                doctor_name, serial_number = send_to_websocket_server(fields)
                
                # Format the congratulations message
                age_str = fields.get('age', '')
                if age_str:
                    age_str = f"Your age: {age_str}. "
                else:
                    age_str = ""
                
                reply_text_sent = f"Congratulations! Dear {fields['patient_name']}, your appointment to {doctor_name} on {fields['date']} has been accepted. {age_str}Your serial number is: {serial_number}."
                
                send_reply_message(reply_text_sent)
                just_sent_reply = True
            else:
                # Format error message without newlines
                reply_text_sent = "Invalid message format. Please use key-value format: dt: YYYY-MM-DD, nm: Name, ph: 12345, did: DoctorID, ref: RefDoctor, mr: Marketing, ag: Age"
                send_reply_message(reply_text_sent)
                just_sent_reply = True
                print(f"Ignored message: {last_message} (invalid structure or missing required fields)")
        time.sleep(0.5)  # Check for new messages every half second
except KeyboardInterrupt:
    print("Script interrupted by user")
finally:
    # Don't quit the driver - leave Chrome open for next run
    print("Script completed. Chrome remains open for next run.")