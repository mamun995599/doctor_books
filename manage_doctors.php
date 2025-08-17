<?php include 'db.php'; ?>
<!DOCTYPE html>
<html>
<head>
  <title>Manage Doctors</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { padding: 2rem; font-size: 1.2rem; }
    .table td, .table th { vertical-align: middle; }
  </style>
</head>
<body>
  <div class="container">
    <h2 class="mb-4">Doctor List</h2>

    <form id="addForm" method="POST" class="form-inline mb-3">
      <input type="text" name="doctor_name" class="form-control mr-2" placeholder="New Doctor Name" required>
      <button class="btn btn-success" name="add">Add Doctor</button>
    </form>

    <?php
    if (isset($_POST['add'])) {
      $name = $conn->real_escape_string($_POST['doctor_name']);
      $conn->query("INSERT INTO books (doctor_name) VALUES ('$name')");
      echo "<script>location.href='manage_doctors.php';</script>";
    }

    if (isset($_POST['delete'])) {
      $id = (int)$_POST['delete'];
      $conn->query("DELETE FROM books WHERE id=$id");
      echo "<script>location.href='manage_doctors.php';</script>";
    }

    if (isset($_POST['edit'])) {
      $id = (int)$_POST['edit_id'];
      $new_name = $conn->real_escape_string($_POST['edit_name']);
      $conn->query("UPDATE books SET doctor_name='$new_name' WHERE id=$id");
      echo "<script>location.href='manage_doctors.php';</script>";
    }
    ?>

    <table class="table table-bordered table-striped">
      <thead class="thead-dark">
        <tr>
          <th>ID</th>
          <th>Doctor Name</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php
        $result = $conn->query("SELECT * FROM books ORDER BY doctor_name");
        while($row = $result->fetch_assoc()) {
          echo "
          <tr>
            <form method='POST'>
              <td>{$row['id']}</td>
              <td>
                <input type='hidden' name='edit_id' value='{$row['id']}'>
                <input type='text' name='edit_name' value='{$row['doctor_name']}' class='form-control'>
              </td>
              <td>
                <button name='edit' class='btn btn-primary btn-sm'>Update</button>
                <button name='delete' value='{$row['id']}' class='btn btn-danger btn-sm'>Delete</button>
              </td>
            </form>
          </tr>";
        }
      ?>
      </tbody>
    </table>

    <hr>
    <h4>🗓 Move Diary Data from One Date to Another</h4>
    <form method="POST" class="form-inline">
      <select name="book_id" class="form-control mr-2" required>
        <option value="">Select Doctor</option>
        <?php
        $result = $conn->query("SELECT * FROM books");
        while($row = $result->fetch_assoc()) {
          echo "<option value='{$row['id']}'>{$row['doctor_name']}</option>";
        }
        ?>
      </select>
      <input type="date" name="from_date" class="form-control mr-2" required>
      <input type="date" name="to_date" class="form-control mr-2" required>
      <button name="move" class="btn btn-warning">Move Data</button>
    </form>

    <?php
    if (isset($_POST['move'])) {
      $book = (int)$_POST['book_id'];
      $from = $_POST['from_date'];
      $to = $_POST['to_date'];

      // Get source diary_details
      $src = $conn->query("SELECT * FROM diary_details WHERE book_id=$book AND entry_date='$from'");
      if ($src->num_rows) {
        $src_row = $src->fetch_assoc();
        $entry_time = $src_row['entry_time'];
        $old_diary_id = $src_row['id'];

        // Delete target diary_details (and its entries via cascade)
        $conn->query("DELETE FROM diary_details WHERE book_id=$book AND entry_date='$to'");

        // Insert new diary_details
        $conn->query("INSERT INTO diary_details (book_id, entry_date, entry_time) VALUES ($book, '$to', '$entry_time')");
        $new_diary_id = $conn->insert_id;

        // Copy entries
        $conn->query("
          INSERT INTO diary_entries (diary_id, name, phone, pa_status, status, fees, ref_doctor, marketing_officer, position)
          SELECT $new_diary_id, name, phone, pa_status, status, fees, ref_doctor, marketing_officer, position
          FROM diary_entries
          WHERE diary_id=$old_diary_id
        ");

        echo "<div class='alert alert-success mt-3'>✅ Data moved from $from to $to</div>";
      } else {
        echo "<div class='alert alert-warning mt-3'>⚠️ No diary found for $from</div>";
      }
    }
    ?>
  </div>
</body>
</html>
